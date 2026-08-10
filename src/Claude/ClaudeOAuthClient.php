<?php

declare(strict_types=1);

namespace App\Claude;

use App\Claude\Exception\ClaudeCredentialsException;
use App\Claude\Exception\ClaudeOAuthException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hands out an access token the Claude endpoints accept, refreshing the stored pair when it is
 * about to expire.
 *
 * The token endpoint rate-limits the exchange itself, sends no Retry-After, and stays locked for
 * minutes once tripped. So a refresh happens only when the access token is genuinely within
 * REFRESH_MARGIN of its expiry, never once per run, and a failed exchange is never tried again
 * inside the same run: it throws, and the caller decides what to do with the cycle.
 *
 * This class throws on every failure. Keeping it loud is what lets the bootstrap command report a
 * precise reason; the provider that consumes it is the layer that degrades to null.
 */
final readonly class ClaudeOAuthClient
{
    /**
     * Public identifier of the Claude Code CLI, shipped in a distributed binary. Not a secret.
     */
    public const string CLIENT_ID = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';

    private const string TOKEN_PATH = 'token';
    private const string REFRESH_GRANT_TYPE = 'refresh_token';
    private const string AUTHORIZATION_CODE_GRANT_TYPE = 'authorization_code';
    private const string REFRESH_MARGIN = 'PT5M';

    public function __construct(
        #[Target('claude_oauth.client')]
        private HttpClientInterface $claudeOAuthClient,
        private ClaudeCredentialsStore $credentialsStore,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ClaudeOAuthException
     * @throws ClaudeCredentialsException
     */
    public function freshAccessToken(): string
    {
        $storedCredentials = $this->credentialsStore->load();

        // Before anything reaches the network: a pair the server has already refused on both slots
        // stays refused until someone logs in again, and every cycle that asks anyway spends two
        // exchanges on the endpoint the operator needs free to repair it.
        if ($storedCredentials->isUnusable()) {
            throw ClaudeOAuthException::sessionAlreadyLost($storedCredentials->unusableSince);
        }

        if (!$storedCredentials->current->isExpiringWithin(new \DateInterval(self::REFRESH_MARGIN), $this->clock->now())) {
            return $storedCredentials->current->accessToken;
        }

        return $this->refresh($storedCredentials)->accessToken;
    }

    /**
     * Turns the code an approved authorization hands back into the very first pair of a session.
     *
     * Nothing is persisted here, unlike refresh(): the file the pair belongs in may still hold a
     * live session, and deciding to replace it is the bootstrap command's call, not this one's.
     *
     * @throws ClaudeOAuthException
     */
    public function exchangeAuthorizationCode(ClaudeAuthorizationRequest $authorizationRequest, string $authorizationCode): ClaudeCredentials
    {
        $decodedResponse = $this->postToken(self::AUTHORIZATION_CODE_GRANT_TYPE, [
            'code' => $authorizationCode,
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => ClaudeAuthorizationRequest::REDIRECT_URI,
            'code_verifier' => $authorizationRequest->codeVerifier,
            'state' => $authorizationRequest->state,
        ]);

        return ClaudeCredentials::fromTokenResponse($decodedResponse, $this->clock->now());
    }

    /**
     * @throws ClaudeOAuthException
     * @throws ClaudeCredentialsException
     */
    public function refresh(StoredClaudeCredentials $storedCredentials): ClaudeCredentials
    {
        $exchangedRefreshToken = $storedCredentials->current->refreshToken;

        try {
            $decodedResponse = $this->exchangeRefreshToken($exchangedRefreshToken);
        } catch (ClaudeOAuthException $refreshFailure) {
            [$decodedResponse, $exchangedRefreshToken] = $this->exchangeReplacedRefreshToken($storedCredentials, $refreshFailure);
        }

        $refreshedCredentials = ClaudeCredentials::fromTokenResponse(
            $decodedResponse,
            $this->clock->now(),
            carriedRefreshToken: $exchangedRefreshToken,
        );

        // Persisted before it is handed out: saving in the caller would open a window where the
        // exchange succeeded, the process died, and the only live refresh token existed in a
        // variable that no longer exists.
        $this->credentialsStore->save($storedCredentials->rotatedTo($refreshedCredentials));

        $this->logger->info('Refreshed the Claude access token', [
            'expires_at' => $refreshedCredentials->expiresAt->format(\DateTimeInterface::RFC3339),
            'access_token_length' => mb_strlen($refreshedCredentials->accessToken),
        ]);

        return $refreshedCredentials;
    }

    /**
     * Only a refused grant is worth one further call, and only with a different token: the
     * rotation may have half-applied, leaving the server on the pair we replaced. Every other
     * failure — a rate limit above all — ends the attempt here, because a second exchange would
     * extend the lockout rather than recover from it.
     *
     * @return array{array<array-key, mixed>, string}
     *
     * @throws ClaudeOAuthException
     */
    private function exchangeReplacedRefreshToken(StoredClaudeCredentials $storedCredentials, ClaudeOAuthException $refreshFailure): array
    {
        if (ClaudeOAuthException::INVALID_GRANT_ERROR_CODE !== $refreshFailure->oauthErrorCode) {
            throw $refreshFailure;
        }

        $replacedCredentials = $storedCredentials->previous;
        if (null === $replacedCredentials) {
            $this->abandonSession($storedCredentials, $refreshFailure);
        }

        $this->logger->warning('The current Claude refresh token was refused, falling back to the one it replaced');

        try {
            return [$this->exchangeRefreshToken($replacedCredentials->refreshToken), $replacedCredentials->refreshToken];
        } catch (ClaudeOAuthException $fallbackFailure) {
            $this->abandonSession($storedCredentials, $fallbackFailure);
        }
    }

    /**
     * Both slots were refused, so every later cycle would spend two exchanges reaching the same
     * conclusion. Record it on the pair itself: the next run then fails without touching the
     * network, and a successful login clears the mark by writing a fresh pair over it.
     *
     * Marking is best effort — an unwritable file must not replace the reason the session was lost
     * with a filesystem complaint.
     *
     * @throws ClaudeOAuthException
     */
    private function abandonSession(StoredClaudeCredentials $storedCredentials, ClaudeOAuthException $cause): never
    {
        try {
            $this->credentialsStore->save($storedCredentials->markedUnusableAt($this->clock->now()));
        } catch (ClaudeCredentialsException $markingFailure) {
            $this->logger->error('Could not record that the Claude session is lost, so the next cycle will try again', [
                'error' => $markingFailure->getMessage(),
            ]);
        }

        throw ClaudeOAuthException::sessionLost($cause);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ClaudeOAuthException
     */
    private function exchangeRefreshToken(string $refreshToken): array
    {
        return $this->postToken(self::REFRESH_GRANT_TYPE, [
            'refresh_token' => $refreshToken,
            'client_id' => self::CLIENT_ID,
        ]);
    }

    /**
     * @param array<string, string> $grantParameters
     *
     * @return array<array-key, mixed>
     *
     * @throws ClaudeOAuthException
     */
    private function postToken(string $grantType, array $grantParameters): array
    {
        $parameters = ['grant_type' => $grantType, ...$grantParameters];

        try {
            // JSON, never a form body: the endpoint rejects application/x-www-form-urlencoded at
            // parse time with "Input should be a valid dictionary or object".
            $response = $this->claudeOAuthClient->request('POST', self::TOKEN_PATH, ['json' => $parameters]);
            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(throw: false);
        } catch (HttpClientExceptionInterface $transportFailure) {
            $this->logger->warning('The Claude token endpoint could not be reached', ['grant_type' => $grantType]);

            throw ClaudeOAuthException::transportFailure($grantType, $transportFailure);
        }

        $decodedResponse = self::decodeJsonObject($rawBody);

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorValue = null === $decodedResponse ? null : ($decodedResponse['error'] ?? null);
            $oauthErrorCode = \is_string($errorValue) ? $errorValue : null;

            $this->logger->warning('The Claude token endpoint refused the exchange', [
                'grant_type' => $grantType,
                'status' => $statusCode,
                'error' => $oauthErrorCode,
            ]);

            throw ClaudeOAuthException::exchangeRefused($grantType, $statusCode, $oauthErrorCode);
        }

        return $decodedResponse ?? throw ClaudeOAuthException::unreadableResponse($grantType);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decodeJsonObject(string $rawBody): ?array
    {
        try {
            $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
