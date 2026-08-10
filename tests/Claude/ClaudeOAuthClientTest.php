<?php

declare(strict_types=1);

namespace App\Tests\Claude;

use App\Claude\ClaudeCredentials;
use App\Claude\ClaudeCredentialsStore;
use App\Claude\ClaudeOAuthClient;
use App\Claude\Exception\ClaudeOAuthException;
use App\Claude\StoredClaudeCredentials;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClaudeOAuthClientTest extends TestCase
{
    private const string OAUTH_BASE_URI = 'https://platform.claude.com/v1/oauth/';
    private const string TOKEN_URL = self::OAUTH_BASE_URI.'token';
    private const string NOW = '2026-08-10T12:00:00+00:00';
    private const string FAR_FROM_EXPIRY = '2026-08-10T14:00:00+00:00';
    private const string CLOSE_TO_EXPIRY = '2026-08-10T12:01:00+00:00';
    private const string STORED_ACCESS_TOKEN = 'access-token-on-disk';
    private const string STORED_REFRESH_TOKEN = 'refresh-token-on-disk';
    private const string REPLACED_REFRESH_TOKEN = 'refresh-token-that-was-replaced';
    private const string ISSUED_ACCESS_TOKEN = 'access-token-just-issued';
    private const string ISSUED_REFRESH_TOKEN = 'refresh-token-just-issued';

    private string $temporaryDirectory;
    private string $credentialsFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-claude-oauth-'.bin2hex(random_bytes(6));
        $this->credentialsFilePath = $this->temporaryDirectory.'/credentials.json';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testATokenThatIsNotNearItsExpiryIsHandedOutWithoutTouchingTheNetwork(): void
    {
        $store = $this->storeHolding(self::FAR_FROM_EXPIRY);
        $httpClient = new MockHttpClient([], self::OAUTH_BASE_URI);

        $accessToken = $this->buildOAuthClient($httpClient, $store)->freshAccessToken();

        self::assertSame(self::STORED_ACCESS_TOKEN, $accessToken);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testATokenNearItsExpiryIsExchangedExactlyOnceAgainstAJsonBody(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $tokenResponse = self::tokenResponse();
        $httpClient = new MockHttpClient([$tokenResponse], self::OAUTH_BASE_URI);

        $accessToken = $this->buildOAuthClient($httpClient, $store)->freshAccessToken();

        self::assertSame(self::ISSUED_ACCESS_TOKEN, $accessToken);
        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame('POST', $tokenResponse->getRequestMethod());
        self::assertSame(self::TOKEN_URL, $tokenResponse->getRequestUrl());
        self::assertContains('Content-Type: application/json', self::requestHeaders($tokenResponse));

        self::assertSame(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => self::STORED_REFRESH_TOKEN,
                'client_id' => ClaudeOAuthClient::CLIENT_ID,
            ],
            self::requestBody($tokenResponse),
        );
    }

    public function testASuccessfulRefreshStoresBothNewTokensAndKeepsThePairTheyReplaced(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $httpClient = new MockHttpClient([self::tokenResponse()], self::OAUTH_BASE_URI);

        $this->buildOAuthClient($httpClient, $store)->freshAccessToken();

        $reloaded = $store->load();
        self::assertSame(self::ISSUED_ACCESS_TOKEN, $reloaded->current->accessToken);
        self::assertSame(self::ISSUED_REFRESH_TOKEN, $reloaded->current->refreshToken);
        self::assertSame('2026-08-10T20:00:00+00:00', $reloaded->current->expiresAt->format(\DateTimeInterface::RFC3339));
        self::assertNotNull($reloaded->previous);
        self::assertSame(self::STORED_ACCESS_TOKEN, $reloaded->previous->accessToken);
        self::assertSame(self::STORED_REFRESH_TOKEN, $reloaded->previous->refreshToken);
    }

    public function testAnAnswerWithoutARefreshTokenCarriesTheSentOneForwardInsteadOfLosingTheSession(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $httpClient = new MockHttpClient(
            [self::jsonResponse(['access_token' => self::ISSUED_ACCESS_TOKEN, 'expires_in' => 28800])],
            self::OAUTH_BASE_URI,
        );

        $this->buildOAuthClient($httpClient, $store)->freshAccessToken();

        $reloaded = $store->load();
        self::assertSame(self::ISSUED_ACCESS_TOKEN, $reloaded->current->accessToken);
        self::assertSame(self::STORED_REFRESH_TOKEN, $reloaded->current->refreshToken);
    }

    public function testARefusedGrantIsTriedOnceMoreWithTheRefreshTokenItReplaced(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY, withReplacedPair: true);
        $refusal = self::jsonResponse(['error' => 'invalid_grant'], 400);
        $success = self::tokenResponse();
        $httpClient = new MockHttpClient([$refusal, $success], self::OAUTH_BASE_URI);

        $accessToken = $this->buildOAuthClient($httpClient, $store)->freshAccessToken();

        self::assertSame(self::ISSUED_ACCESS_TOKEN, $accessToken);
        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertSame(self::STORED_REFRESH_TOKEN, self::requestBody($refusal)['refresh_token'] ?? null);
        self::assertSame(self::REPLACED_REFRESH_TOKEN, self::requestBody($success)['refresh_token'] ?? null);
    }

    public function testARefusedGrantWithNothingToFallBackOnNamesTheManualRemedyAndLeavesTheFileAlone(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $httpClient = new MockHttpClient([self::jsonResponse(['error' => 'invalid_grant'], 400)], self::OAUTH_BASE_URI);
        $storedFileBefore = self::readCredentialsFile($this->credentialsFilePath);

        try {
            $this->buildOAuthClient($httpClient, $store)->freshAccessToken();
            self::fail('A refused grant should not be swallowed.');
        } catch (ClaudeOAuthException $refusal) {
            self::assertStringContainsString('app:claude:login', $refusal->getMessage());
        }

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame($storedFileBefore, self::readCredentialsFile($this->credentialsFilePath));
    }

    public function testARateLimitedExchangeEndsTheAttemptWithoutASecondCall(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY, withReplacedPair: true);
        $httpClient = new MockHttpClient([self::jsonResponse(['error' => 'rate_limit_error'], 429)], self::OAUTH_BASE_URI);
        $storedFileBefore = self::readCredentialsFile($this->credentialsFilePath);

        $this->expectException(ClaudeOAuthException::class);

        try {
            $this->buildOAuthClient($httpClient, $store)->freshAccessToken();
        } finally {
            self::assertSame(1, $httpClient->getRequestsCount());
            self::assertSame($storedFileBefore, self::readCredentialsFile($this->credentialsFilePath));
        }
    }

    public function testAnEmptyBodiedRateLimitIsStillReportedAsARefusalRatherThanCrashing(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $httpClient = new MockHttpClient([new MockResponse('', ['http_code' => 429])], self::OAUTH_BASE_URI);

        $this->expectException(ClaudeOAuthException::class);
        $this->expectExceptionMessage('HTTP 429');

        $this->buildOAuthClient($httpClient, $store)->freshAccessToken();
    }

    public function testATransportFailureSurfacesAsAnOAuthFailureAndLeavesTheFileAlone(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $httpClient = new MockHttpClient([new MockResponse('', ['error' => 'connection refused'])], self::OAUTH_BASE_URI);
        $storedFileBefore = self::readCredentialsFile($this->credentialsFilePath);

        $this->expectException(ClaudeOAuthException::class);

        try {
            $this->buildOAuthClient($httpClient, $store)->freshAccessToken();
        } finally {
            self::assertSame($storedFileBefore, self::readCredentialsFile($this->credentialsFilePath));
        }
    }

    public function testAnAnswerWithoutAnAccessTokenIsRefusedAndLeavesTheFileAlone(): void
    {
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $httpClient = new MockHttpClient([self::jsonResponse(['expires_in' => 28800])], self::OAUTH_BASE_URI);
        $storedFileBefore = self::readCredentialsFile($this->credentialsFilePath);

        $this->expectException(ClaudeOAuthException::class);

        try {
            $this->buildOAuthClient($httpClient, $store)->freshAccessToken();
        } finally {
            self::assertSame($storedFileBefore, self::readCredentialsFile($this->credentialsFilePath));
        }
    }

    public function testNothingThatIsLoggedCarriesATokenValue(): void
    {
        $logger = new RecordingLoggerStub();
        $store = $this->storeHolding(self::CLOSE_TO_EXPIRY);
        $httpClient = new MockHttpClient([self::tokenResponse()], self::OAUTH_BASE_URI);

        $this->buildOAuthClient($httpClient, $store, $logger)->freshAccessToken();

        $loggedText = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertNotSame('[]', $loggedText);
        foreach ([self::STORED_ACCESS_TOKEN, self::STORED_REFRESH_TOKEN, self::ISSUED_ACCESS_TOKEN, self::ISSUED_REFRESH_TOKEN] as $token) {
            self::assertStringNotContainsString($token, $loggedText);
        }
    }

    private function buildOAuthClient(
        MockHttpClient $httpClient,
        ClaudeCredentialsStore $store,
        ?RecordingLoggerStub $logger = null,
    ): ClaudeOAuthClient {
        return new ClaudeOAuthClient($httpClient, $store, new MockClock(self::NOW), $logger ?? new NullLogger());
    }

    private function storeHolding(string $expiresAt, bool $withReplacedPair = false): ClaudeCredentialsStore
    {
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $store->save(new StoredClaudeCredentials(
            self::credentials(self::STORED_ACCESS_TOKEN, self::STORED_REFRESH_TOKEN, $expiresAt),
            $withReplacedPair
                ? self::credentials('access-token-that-was-replaced', self::REPLACED_REFRESH_TOKEN, self::NOW)
                : null,
        ));

        return $store;
    }

    private static function credentials(string $accessToken, string $refreshToken, string $expiresAt): ClaudeCredentials
    {
        return ClaudeCredentials::create(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: new \DateTimeImmutable($expiresAt),
            scopes: ['user:profile'],
            obtainedAt: new \DateTimeImmutable(self::NOW),
        );
    }

    private static function tokenResponse(): MockResponse
    {
        return self::jsonResponse([
            'access_token' => self::ISSUED_ACCESS_TOKEN,
            'refresh_token' => self::ISSUED_REFRESH_TOKEN,
            'expires_in' => 28800,
            'scope' => 'user:profile user:inference',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(array $body, int $statusCode = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $statusCode,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function requestBody(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? null;
        if (!\is_string($body)) {
            self::fail('The token request carries no readable body.');
        }

        $decodedBody = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decodedBody)) {
            self::fail('The token request body is not a JSON object.');
        }

        return $decodedBody;
    }

    /**
     * @return list<string>
     */
    private static function requestHeaders(MockResponse $response): array
    {
        $headers = $response->getRequestOptions()['headers'] ?? [];
        if (!\is_array($headers)) {
            self::fail('The token request carries no readable headers.');
        }

        $stringHeaders = [];
        foreach ($headers as $header) {
            if (\is_string($header)) {
                $stringHeaders[] = $header;
            }
        }

        return $stringHeaders;
    }

    private static function readCredentialsFile(string $credentialsFilePath): string
    {
        $contents = file_get_contents($credentialsFilePath);
        if (false === $contents) {
            self::fail(\sprintf('The credentials file at "%s" could not be read back.', $credentialsFilePath));
        }

        return $contents;
    }
}
