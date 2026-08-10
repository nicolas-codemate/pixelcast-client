<?php

declare(strict_types=1);

namespace App\Tests\Claude;

use App\Claude\ClaudeCredentials;
use App\Claude\Exception\ClaudeOAuthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ClaudeCredentialsTest extends TestCase
{
    private const string NOW = '2026-08-10T12:00:00+00:00';
    private const string ACCESS_TOKEN = 'access-token-from-the-response';
    private const string REFRESH_TOKEN = 'refresh-token-from-the-response';
    private const string SENT_REFRESH_TOKEN = 'refresh-token-that-was-sent';
    private const string CREDENTIALS_FILE_PATH = '/tmp/pixelcast-claude/credentials.json';

    public function testATokenResponseIsReadIntoAPairExpiringAfterTheAnnouncedNumberOfSeconds(): void
    {
        $credentials = ClaudeCredentials::fromTokenResponse(
            [
                'access_token' => self::ACCESS_TOKEN,
                'refresh_token' => self::REFRESH_TOKEN,
                'expires_in' => 28800,
                'scope' => 'user:profile user:inference',
            ],
            self::now(),
        );

        self::assertSame(self::ACCESS_TOKEN, $credentials->accessToken);
        self::assertSame(self::REFRESH_TOKEN, $credentials->refreshToken);
        self::assertSame('2026-08-10T20:00:00+00:00', $credentials->expiresAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(self::NOW, $credentials->obtainedAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(['user:profile', 'user:inference'], $credentials->scopes);
    }

    public function testAnExpiryAnnouncedAsANumericStringIsReadLikeAnInteger(): void
    {
        $credentials = ClaudeCredentials::fromTokenResponse(
            ['access_token' => self::ACCESS_TOKEN, 'refresh_token' => self::REFRESH_TOKEN, 'expires_in' => '28800'],
            self::now(),
        );

        self::assertSame('2026-08-10T20:00:00+00:00', $credentials->expiresAt->format(\DateTimeInterface::RFC3339));
    }

    /**
     * Neither shape could be observed live, so both have to be survivable.
     */
    #[DataProvider('provideAbsoluteExpiryCases')]
    public function testAnAbsoluteExpiryIsAcceptedInEveryShapeTheServerCouldSendIt(mixed $expiresAt): void
    {
        $credentials = ClaudeCredentials::fromTokenResponse(
            ['access_token' => self::ACCESS_TOKEN, 'refresh_token' => self::REFRESH_TOKEN, 'expires_at' => $expiresAt],
            self::now(),
        );

        self::assertSame('2026-08-10T20:00:00+00:00', $credentials->expiresAt->format(\DateTimeInterface::RFC3339));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideAbsoluteExpiryCases(): iterable
    {
        yield 'RFC 3339 string' => ['2026-08-10T20:00:00+00:00'];
        yield 'epoch in seconds' => [1786392000];
        yield 'epoch in milliseconds, the shape the CLI stores' => [1786392000000];
        yield 'epoch in seconds as a string' => ['1786392000'];
    }

    public function testARefreshAnswerWithoutARefreshTokenKeepsTheOneThatWasSent(): void
    {
        $credentials = ClaudeCredentials::fromTokenResponse(
            ['access_token' => self::ACCESS_TOKEN, 'expires_in' => 3600],
            self::now(),
            carriedRefreshToken: self::SENT_REFRESH_TOKEN,
        );

        self::assertSame(self::SENT_REFRESH_TOKEN, $credentials->refreshToken);
    }

    public function testARefreshAnswerWithoutARefreshTokenAndNothingToCarryForwardIsRefused(): void
    {
        $this->expectException(ClaudeOAuthException::class);
        $this->expectExceptionMessage('refresh_token');

        ClaudeCredentials::fromTokenResponse(['access_token' => self::ACCESS_TOKEN, 'expires_in' => 3600], self::now());
    }

    public function testATokenResponseWithoutAnAccessTokenIsRefused(): void
    {
        $this->expectException(ClaudeOAuthException::class);
        $this->expectExceptionMessage('access_token');

        ClaudeCredentials::fromTokenResponse(['refresh_token' => self::REFRESH_TOKEN, 'expires_in' => 3600], self::now());
    }

    public function testATokenResponseWithoutAnyExpiryIsRefused(): void
    {
        $this->expectException(ClaudeOAuthException::class);
        $this->expectExceptionMessage('expires_in');

        ClaudeCredentials::fromTokenResponse(
            ['access_token' => self::ACCESS_TOKEN, 'refresh_token' => self::REFRESH_TOKEN],
            self::now(),
        );
    }

    public function testATokenResponseWithoutAScopeYieldsAnEmptyScopeListRatherThanAFailure(): void
    {
        $credentials = ClaudeCredentials::fromTokenResponse(
            ['access_token' => self::ACCESS_TOKEN, 'refresh_token' => self::REFRESH_TOKEN, 'expires_in' => 3600],
            self::now(),
        );

        self::assertSame([], $credentials->scopes);
    }

    #[DataProvider('provideExpiryMarginCases')]
    public function testAPairCountsAsExpiringOnlyOnceTheMarginReachesItsExpiry(string $expiresAt, bool $isExpiring): void
    {
        $credentials = self::credentialsExpiringAt($expiresAt);

        self::assertSame(
            $isExpiring,
            $credentials->isExpiringWithin(new \DateInterval('PT5M'), new MockClock(self::NOW)->now()),
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideExpiryMarginCases(): iterable
    {
        yield 'well beyond the margin' => ['2026-08-10T14:00:00+00:00', false];
        yield 'one second beyond the margin' => ['2026-08-10T12:05:01+00:00', false];
        yield 'exactly on the margin' => ['2026-08-10T12:05:00+00:00', true];
        yield 'inside the margin' => ['2026-08-10T12:04:59+00:00', true];
        yield 'already expired' => ['2026-08-10T11:00:00+00:00', true];
    }

    public function testEveryFieldSurvivesAnExportRestoreRoundTrip(): void
    {
        $credentials = ClaudeCredentials::create(
            accessToken: self::ACCESS_TOKEN,
            refreshToken: self::REFRESH_TOKEN,
            expiresAt: new \DateTimeImmutable('2026-08-10T20:00:00+00:00'),
            scopes: ['user:profile', 'user:inference'],
            obtainedAt: self::now(),
        );

        $restored = ClaudeCredentials::restoreFromPersistence(
            $credentials->exportForPersistence(),
            self::CREDENTIALS_FILE_PATH,
            'current',
        );

        self::assertSame(self::ACCESS_TOKEN, $restored->accessToken);
        self::assertSame(self::REFRESH_TOKEN, $restored->refreshToken);
        self::assertSame('2026-08-10T20:00:00+00:00', $restored->expiresAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(self::NOW, $restored->obtainedAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(['user:profile', 'user:inference'], $restored->scopes);
    }

    public function testAnInstantIsPersistedInUtcWhateverTimezoneItCarries(): void
    {
        $credentials = ClaudeCredentials::create(
            accessToken: self::ACCESS_TOKEN,
            refreshToken: self::REFRESH_TOKEN,
            expiresAt: new \DateTimeImmutable('2026-08-10T22:00:00', new \DateTimeZone('Europe/Paris')),
            scopes: [],
            obtainedAt: self::now(),
        );

        self::assertSame('2026-08-10T20:00:00+00:00', $credentials->exportForPersistence()['expiresAt']);
    }

    #[DataProvider('provideEmptyTokenCases')]
    public function testAPairCannotBeBuiltWithAnEmptyToken(string $accessToken, string $refreshToken): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClaudeCredentials::create($accessToken, $refreshToken, self::now(), [], self::now());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEmptyTokenCases(): iterable
    {
        yield 'empty access token' => ['', self::REFRESH_TOKEN];
        yield 'empty refresh token' => [self::ACCESS_TOKEN, ''];
    }

    private static function credentialsExpiringAt(string $expiresAt): ClaudeCredentials
    {
        return ClaudeCredentials::create(
            accessToken: self::ACCESS_TOKEN,
            refreshToken: self::REFRESH_TOKEN,
            expiresAt: new \DateTimeImmutable($expiresAt),
            scopes: [],
            obtainedAt: self::now(),
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new MockClock(self::NOW)->now();
    }
}
