<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Projection;

use App\Simulator\Projection\FreshnessProjection;
use PHPUnit\Framework\TestCase;

final class FreshnessProjectionTest extends TestCase
{
    private const int DEFAULT_STALE_AFTER_SECONDS = 3600;
    private const string DEFAULT_STALE_BEHAVIOR = 'dim';

    public function testAFreshPushIsNotStaleAndAgesFromItsInstant(): void
    {
        $pushedAt = new \DateTimeImmutable('2026-08-22T10:00:00+00:00');
        $now = $pushedAt->modify('+42 seconds');

        $freshness = self::project([], $pushedAt, $now);

        self::assertSame(42, $freshness['age']);
        self::assertFalse($freshness['stale']);
        self::assertSame(self::DEFAULT_STALE_AFTER_SECONDS, $freshness['staleAfter']);
        self::assertSame(self::DEFAULT_STALE_BEHAVIOR, $freshness['staleBehavior']);
    }

    public function testSilenceLongerThanStaleAfterIsStale(): void
    {
        $pushedAt = new \DateTimeImmutable('2026-08-22T10:00:00+00:00');
        $now = $pushedAt->modify('+1 hour +1 second');

        $freshness = self::project([], $pushedAt, $now);

        self::assertSame(3601, $freshness['age']);
        self::assertTrue($freshness['stale']);
    }

    public function testAStaleAfterOfZeroNeverGoesStale(): void
    {
        $pushedAt = new \DateTimeImmutable('2026-08-22T10:00:00+00:00');
        $now = $pushedAt->modify('+7 days');

        $freshness = self::project(['staleAfter' => 0], $pushedAt, $now);

        self::assertSame(0, $freshness['staleAfter']);
        self::assertFalse($freshness['stale']);
    }

    public function testThePushedStaleBehaviorWinsOverTheDefault(): void
    {
        $pushedAt = new \DateTimeImmutable('2026-08-22T10:00:00+00:00');

        $freshness = self::project(['staleBehavior' => 'badge'], $pushedAt, $pushedAt);

        self::assertSame('badge', $freshness['staleBehavior']);
    }

    public function testAPayloadWithNoRecordedInstantIsFreshAndAgeless(): void
    {
        $freshness = self::project([], null, new \DateTimeImmutable('2026-08-22T10:00:00+00:00'));

        self::assertSame(0, $freshness['age']);
        self::assertFalse($freshness['stale']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{age: int, stale: bool, staleAfter: int, staleBehavior: string}
     */
    private static function project(array $payload, ?\DateTimeImmutable $pushedAt, \DateTimeImmutable $now): array
    {
        return FreshnessProjection::of(
            $payload,
            $pushedAt,
            $now,
            self::DEFAULT_STALE_AFTER_SECONDS,
            self::DEFAULT_STALE_BEHAVIOR,
        );
    }
}
