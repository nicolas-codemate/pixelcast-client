<?php

declare(strict_types=1);

namespace App\Claude;

use App\Claude\Exception\ClaudeCredentialsException;
use App\Claude\Exception\ClaudeOAuthException;

/**
 * One Claude OAuth pair: the access token the usage endpoint accepts, the refresh token that
 * renews it, and the instant the access token stops being usable.
 *
 * The expiry is kept as an absolute instant rather than as the "expires_in" the server sends, so
 * that a reader needs no knowledge of when the pair was obtained.
 */
final readonly class ClaudeCredentials
{
    private const string ACCESS_TOKEN_RESPONSE_FIELD = 'access_token';
    private const string REFRESH_TOKEN_RESPONSE_FIELD = 'refresh_token';
    private const string EXPIRES_IN_RESPONSE_FIELD = 'expires_in';
    private const string EXPIRES_AT_RESPONSE_FIELD = 'expires_at';
    private const string SCOPE_RESPONSE_FIELD = 'scope';
    private const string SCOPE_SEPARATOR = ' ';
    private const string PERSISTED_TIMEZONE = 'UTC';

    /**
     * An absolute expiry that large cannot be a number of seconds since 1970 — it is the same
     * instant expressed in milliseconds, which is how the Claude CLI stores it on disk. Reading it
     * as seconds would place the expiry in 1970 and make every single run refresh, which is the
     * fastest way to trip the rate limit on the token endpoint.
     */
    private const int HIGHEST_PLAUSIBLE_EPOCH_IN_SECONDS = 10000000000;

    /**
     * @param list<string> $scopes
     */
    private function __construct(
        public string $accessToken,
        public string $refreshToken,
        public \DateTimeImmutable $expiresAt,
        public array $scopes,
        public \DateTimeImmutable $obtainedAt,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public static function create(
        string $accessToken,
        string $refreshToken,
        \DateTimeImmutable $expiresAt,
        array $scopes,
        \DateTimeImmutable $obtainedAt,
    ): self {
        if ('' === $accessToken) {
            throw new \InvalidArgumentException('A Claude access token cannot be empty.');
        }

        if ('' === $refreshToken) {
            throw new \InvalidArgumentException('A Claude refresh token cannot be empty.');
        }

        return new self($accessToken, $refreshToken, $expiresAt, $scopes, $obtainedAt);
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     * @param string|null $carriedRefreshToken the refresh token that was sent, kept when the answer omits one
     *
     * @throws ClaudeOAuthException
     */
    public static function fromTokenResponse(
        array $decodedResponse,
        \DateTimeImmutable $now,
        ?string $carriedRefreshToken = null,
    ): self {
        $accessToken = self::readNonEmptyString($decodedResponse, self::ACCESS_TOKEN_RESPONSE_FIELD)
            ?? throw ClaudeOAuthException::missingTokenResponseField(self::ACCESS_TOKEN_RESPONSE_FIELD);

        // A refresh grant is allowed to answer without a refresh token, which means the one that
        // was sent still stands. Refusing that answer would throw away a working session.
        $refreshToken = self::readNonEmptyString($decodedResponse, self::REFRESH_TOKEN_RESPONSE_FIELD)
            ?? $carriedRefreshToken;

        if (null === $refreshToken || '' === $refreshToken) {
            throw ClaudeOAuthException::missingTokenResponseField(self::REFRESH_TOKEN_RESPONSE_FIELD);
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: self::readExpiry($decodedResponse, $now),
            scopes: self::readScopes($decodedResponse),
            obtainedAt: $now,
        );
    }

    public function isExpiringWithin(\DateInterval $margin, \DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now->add($margin);
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresAt: string, scopes: list<string>, obtainedAt: string}
     */
    public function exportForPersistence(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'expiresAt' => self::formatInUtc($this->expiresAt),
            'scopes' => $this->scopes,
            'obtainedAt' => self::formatInUtc($this->obtainedAt),
        ];
    }

    /**
     * @param array<array-key, mixed> $persistedCredentials
     * @param string $slotName which of the two slots of the file is being read, for the error message
     *
     * @throws ClaudeCredentialsException
     */
    public static function restoreFromPersistence(array $persistedCredentials, string $credentialsFilePath, string $slotName): self
    {
        return new self(
            accessToken: self::readNonEmptyString($persistedCredentials, 'accessToken')
                ?? throw ClaudeCredentialsException::malformedField($credentialsFilePath, $slotName.'.accessToken'),
            refreshToken: self::readNonEmptyString($persistedCredentials, 'refreshToken')
                ?? throw ClaudeCredentialsException::malformedField($credentialsFilePath, $slotName.'.refreshToken'),
            expiresAt: self::readPersistedInstant($persistedCredentials, 'expiresAt', $credentialsFilePath, $slotName),
            scopes: self::readPersistedScopes($persistedCredentials),
            obtainedAt: self::readPersistedInstant($persistedCredentials, 'obtainedAt', $credentialsFilePath, $slotName),
        );
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     *
     * @throws ClaudeOAuthException
     */
    private static function readExpiry(array $decodedResponse, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $expiresIn = $decodedResponse[self::EXPIRES_IN_RESPONSE_FIELD] ?? null;
        if (is_numeric($expiresIn)) {
            return $now->setTimestamp($now->getTimestamp() + (int) $expiresIn);
        }

        $expiresAt = $decodedResponse[self::EXPIRES_AT_RESPONSE_FIELD] ?? null;
        if (is_numeric($expiresAt)) {
            $epoch = (int) $expiresAt;

            return new \DateTimeImmutable(
                '@'.($epoch > self::HIGHEST_PLAUSIBLE_EPOCH_IN_SECONDS ? intdiv($epoch, 1000) : $epoch),
            );
        }

        if (\is_string($expiresAt) && '' !== $expiresAt) {
            try {
                return new \DateTimeImmutable($expiresAt);
            } catch (\DateMalformedStringException) {
                throw ClaudeOAuthException::missingTokenResponseField(self::EXPIRES_AT_RESPONSE_FIELD);
            }
        }

        throw ClaudeOAuthException::missingTokenResponseField(self::EXPIRES_IN_RESPONSE_FIELD);
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     *
     * @return list<string>
     */
    private static function readScopes(array $decodedResponse): array
    {
        $scope = $decodedResponse[self::SCOPE_RESPONSE_FIELD] ?? null;
        if (!\is_string($scope)) {
            return [];
        }

        return array_values(array_filter(
            explode(self::SCOPE_SEPARATOR, $scope),
            static fn (string $singleScope): bool => '' !== $singleScope,
        ));
    }

    /**
     * @param array<array-key, mixed> $persistedCredentials
     *
     * @return list<string>
     */
    private static function readPersistedScopes(array $persistedCredentials): array
    {
        $scopes = $persistedCredentials['scopes'] ?? null;
        if (!\is_array($scopes)) {
            return [];
        }

        $readableScopes = [];
        foreach ($scopes as $scope) {
            if (\is_string($scope) && '' !== $scope) {
                $readableScopes[] = $scope;
            }
        }

        return $readableScopes;
    }

    /**
     * @param array<array-key, mixed> $persistedCredentials
     *
     * @throws ClaudeCredentialsException
     */
    private static function readPersistedInstant(
        array $persistedCredentials,
        string $fieldName,
        string $credentialsFilePath,
        string $slotName,
    ): \DateTimeImmutable {
        $rawInstant = self::readNonEmptyString($persistedCredentials, $fieldName);
        $instant = null === $rawInstant
            ? false
            : \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $rawInstant);

        if (false === $instant) {
            throw ClaudeCredentialsException::malformedField($credentialsFilePath, $slotName.'.'.$fieldName);
        }

        return $instant;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function readNonEmptyString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function formatInUtc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(self::PERSISTED_TIMEZONE))->format(\DateTimeInterface::RFC3339);
    }
}
