<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Health\SyncGroupFreshness;
use App\Tests\Factory\SyncHealthScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncHealthCheckerTest extends TestCase
{
    private SyncHealthScenario $scenario;

    protected function setUp(): void
    {
        $this->scenario = new SyncHealthScenario();
    }

    public function testAGroupThatNeverPushedIsStale(): void
    {
        $freshnessPerSyncGroup = $this->scenario->checkerFor('syncs-valid.yaml')->checkEnabledSyncGroups();

        self::assertCount(1, $freshnessPerSyncGroup);
        self::assertSame('weather', $freshnessPerSyncGroup[0]->syncType);
        self::assertNull($freshnessPerSyncGroup[0]->ageInSeconds);
        self::assertTrue($freshnessPerSyncGroup[0]->isStale());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideElapsedMinutesSinceTheLastPush(): iterable
    {
        yield 'within the interval' => [30, false];
        yield 'one missed cycle' => [59, false];
        yield 'two intervals exactly' => [60, false];
        yield 'two missed cycles' => [91, true];
    }

    #[DataProvider('provideElapsedMinutesSinceTheLastPush')]
    public function testStalenessFollowsTheAgeOfTheLastPush(int $elapsedMinutes, bool $expectedToBeStale): void
    {
        $this->scenario->store->recordSuccess('weather');
        $this->scenario->clock->modify(\sprintf('+%d minutes', $elapsedMinutes));

        $freshnessPerSyncGroup = $this->scenario->checkerFor('syncs-valid.yaml')->checkEnabledSyncGroups();

        self::assertSame($elapsedMinutes * 60, $freshnessPerSyncGroup[0]->ageInSeconds);
        self::assertSame($expectedToBeStale, $freshnessPerSyncGroup[0]->isStale());
    }

    public function testEachGroupCarriesItsOwnThreshold(): void
    {
        $freshnessPerSyncGroup = $this->scenario->checkerFor('syncs-trackers-enabled.yaml')->checkEnabledSyncGroups();

        self::assertSame('weather', $freshnessPerSyncGroup[0]->syncType);
        self::assertSame(5400, $freshnessPerSyncGroup[0]->staleAfterInSeconds);
        self::assertSame('coingecko', $freshnessPerSyncGroup[1]->syncType);
        self::assertSame(900, $freshnessPerSyncGroup[1]->staleAfterInSeconds);
    }

    public function testADisabledGroupIsNotWatched(): void
    {
        $this->scenario->store->recordSuccess('weather');

        $freshnessPerSyncGroup = $this->scenario->checkerFor('syncs-all-disabled.yaml')->checkEnabledSyncGroups();

        self::assertSame([], $freshnessPerSyncGroup);
    }

    public function testAGroupOutsideItsActiveWindowIsNotStale(): void
    {
        $this->scenario->useMarketClockAt(SyncHealthScenario::SATURDAY_NOON);

        $boursorama = self::freshnessOf($this->scenario->checkerFor('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertFalse($boursorama->insideActiveWindow);
        self::assertNull($boursorama->secondsSinceWindowOpened);
        self::assertFalse($boursorama->isStale());
    }

    public function testAGroupInsideItsActiveWindowIsStillJudged(): void
    {
        $this->scenario->useMarketClockAt('2026-08-03 12:00:00');

        $boursorama = self::freshnessOf($this->scenario->checkerFor('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertTrue($boursorama->insideActiveWindow);
        self::assertNull($boursorama->ageInSeconds);
        self::assertTrue($boursorama->isStale());
    }

    public function testAGroupIsNotStaleRightAfterItsWindowReopened(): void
    {
        $this->scenario->useMarketClockAt(SyncHealthScenario::FRIDAY_CLOSING);
        $this->scenario->store->recordSuccess('boursorama');
        $this->scenario->clock->modify('2026-08-10 09:05:00');

        $boursorama = self::freshnessOf($this->scenario->checkerFor('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertSame(300, $boursorama->secondsSinceWindowOpened);
        self::assertSame(228000, $boursorama->ageInSeconds);
        self::assertFalse($boursorama->isStale());
    }

    public function testAGroupIsStaleAgainOnceItsWindowHasBeenOpenLongEnough(): void
    {
        $this->scenario->useMarketClockAt(SyncHealthScenario::FRIDAY_CLOSING);
        $this->scenario->store->recordSuccess('boursorama');
        $this->scenario->clock->modify('2026-08-10 10:00:00');

        $boursorama = self::freshnessOf($this->scenario->checkerFor('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertSame(3600, $boursorama->secondsSinceWindowOpened);
        self::assertTrue($boursorama->isStale());
    }

    /**
     * @param list<SyncGroupFreshness> $freshnessPerSyncGroup
     */
    private static function freshnessOf(array $freshnessPerSyncGroup, string $syncType): SyncGroupFreshness
    {
        foreach ($freshnessPerSyncGroup as $freshness) {
            if ($syncType === $freshness->syncType) {
                return $freshness;
            }
        }

        self::fail(\sprintf('No freshness was reported for the sync group "%s".', $syncType));
    }
}
