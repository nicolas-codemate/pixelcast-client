<?php

declare(strict_types=1);

namespace App\Claude;

use App\Claude\Exception\ClaudeOAuthException;

/**
 * The PKCE parameters of one login attempt, and the two things they are for: the URL an operator
 * approves in a browser, and the reading of the line that approval displays.
 *
 * The authorization endpoint sits at /oauth/authorize, without the /v1 the token endpoint carries,
 * so it is written here in full rather than resolved against the scoped client's base URI. The
 * device-code grant would spare the paste, but the authorization server refuses it to this client.
 */
final readonly class ClaudeAuthorizationRequest
{
    public const string REDIRECT_URI = 'https://console.anthropic.com/oauth/code/callback';

    /**
     * The scope set the Claude Code CLI asks for, sent whole: the usage endpoint is reached with the
     * session of a CLI, not with a scope of our own choosing.
     */
    public const string SCOPE = 'user:profile user:inference user:sessions:claude_code user:file_upload user:mcp_servers';

    private const string AUTHORIZATION_URL = 'https://platform.claude.com/oauth/authorize';
    private const string CODE_CHALLENGE_METHOD = 'S256';
    private const string CODE_CHALLENGE_ALGORITHM = 'sha256';

    /**
     * What the approval page puts between the code and the state it echoes back.
     */
    private const string APPROVAL_SEPARATOR = '#';

    private const int RANDOM_TOKEN_BYTES = 32;

    private function __construct(
        public string $codeVerifier,
        public string $state,
    ) {
    }

    public static function create(string $codeVerifier, string $state): self
    {
        if ('' === $codeVerifier || '' === $state) {
            throw new \InvalidArgumentException('A Claude authorization request needs both a code verifier and a state.');
        }

        return new self($codeVerifier, $state);
    }

    public static function createRandom(): self
    {
        return new self(self::randomUrlSafeToken(), self::randomUrlSafeToken());
    }

    public function authorizationUrl(): string
    {
        return self::AUTHORIZATION_URL.'?'.http_build_query(
            [
                // Out-of-band mode: the page displays the code instead of redirecting to a callback
                // this host could not serve anyway.
                'code' => 'true',
                'client_id' => ClaudeOAuthClient::CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => self::REDIRECT_URI,
                'scope' => self::SCOPE,
                'code_challenge' => $this->codeChallenge(),
                'code_challenge_method' => self::CODE_CHALLENGE_METHOD,
                'state' => $this->state,
            ],
            // The scopes are separated by spaces, and RFC 3986 writes a space "%20" where the
            // default writes "+". Both are read as a space by a form decoder, only the first by a
            // strict URL one, and which of the two is at the other end is not ours to know.
            encoding_type: \PHP_QUERY_RFC3986,
        );
    }

    /**
     * The approval page displays "<code>#<state>". The state is the only evidence that the code
     * belongs to the attempt this run started, so it is checked before the code is worth exchanging.
     *
     * @throws ClaudeOAuthException
     */
    public function codeFromApprovalResult(string $approvalResult): string
    {
        $approvalParts = explode(self::APPROVAL_SEPARATOR, trim($approvalResult));
        if (2 !== \count($approvalParts)) {
            throw ClaudeOAuthException::unreadableApprovalResult();
        }

        [$authorizationCode, $returnedState] = $approvalParts;
        if ('' === $authorizationCode) {
            throw ClaudeOAuthException::unreadableApprovalResult();
        }

        if (!hash_equals($this->state, $returnedState)) {
            throw ClaudeOAuthException::approvalStateMismatch();
        }

        return $authorizationCode;
    }

    private function codeChallenge(): string
    {
        return self::base64UrlEncode(hash(self::CODE_CHALLENGE_ALGORITHM, $this->codeVerifier, binary: true));
    }

    private static function randomUrlSafeToken(): string
    {
        return self::base64UrlEncode(random_bytes(self::RANDOM_TOKEN_BYTES));
    }

    private static function base64UrlEncode(string $rawBytes): string
    {
        return rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
    }
}
