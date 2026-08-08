<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Health\LastSuccessfulSyncStore;
use App\Health\SyncGroupFreshness;
use App\Health\SyncHealthChecker;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncHealthCheckerTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string PUSH_INSTANT = '2026-08-03 10:00:00';
    private const string MARKET_TIMEZONE = 'Europe/Paris';
    private const string FRIDAY_CLOSING = '2026-08-07 17:45:00';
    private const string SATURDAY_NOON = '2026-08-08 12:00:00';

    private MockClock $clock;
    private LastSuccessfulSyncStore $store;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::PUSH_INSTANT);
        $this->store = new LastSuccessfulSyncStore(new ArrayAdapter(), $this->clock);
    }

    public function testAGroupThatNeverPushedIsStale(): void
    {
        $freshnessPerSyncGroup = $this->createChecker('syncs-valid.yaml')->checkEnabledSyncGroups();

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
        $this->store->recordSuccess('weather');
        $this->clock->modify(\sprintf('+%d minutes', $elapsedMinutes));

        $freshnessPerSyncGroup = $this->createChecker('syncs-valid.yaml')->checkEnabledSyncGroups();

        self::assertSame($elapsedMinutes * 60, $freshnessPerSyncGroup[0]->ageInSeconds);
        self::assertSame($expectedToBeStale, $freshnessPerSyncGroup[0]->isStale());
    }

    public function testEachGroupCarriesItsOwnThreshold(): void
    {
        $freshnessPerSyncGroup = $this->createChecker('syncs-trackers-enabled.yaml')->checkEnabledSyncGroups();

        self::assertSame('weather', $freshnessPerSyncGroup[0]->syncType);
        self::assertSame(5400, $freshnessPerSyncGroup[0]->staleAfterInSeconds);
        self::assertSame('coingecko', $freshnessPerSyncGroup[1]->syncType);
        self::assertSame(900, $freshnessPerSyncGroup[1]->staleAfterInSeconds);
    }

    public function testADisabledGroupIsNotWatched(): void
    {
        $this->store->recordSuccess('weather');

        $freshnessPerSyncGroup = $this->createChecker('syncs-all-disabled.yaml')->checkEnabledSyncGroups();

        self::assertSame([], $freshnessPerSyncGroup);
    }

    public function testAGroupOutsideItsActiveWindowIsNotStale(): void
    {
        $this->useMarketClockAt(self::SATURDAY_NOON);

        $boursorama = self::freshnessOf($this->createChecker('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertFalse($boursorama->insideActiveWindow);
        self::assertNull($boursorama->secondsSinceWindowOpened);
        self::assertFalse($boursorama->isStale());
    }

    public function testAGroupInsideItsActiveWindowIsStillJudged(): void
    {
        $this->useMarketClockAt('2026-08-03 12:00:00');

        $boursorama = self::freshnessOf($this->createChecker('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertTrue($boursorama->insideActiveWindow);
        self::assertNull($boursorama->ageInSeconds);
        self::assertTrue($boursorama->isStale());
    }

    public function testAGroupIsNotStaleRightAfterItsWindowReopened(): void
    {
        $this->useMarketClockAt(self::FRIDAY_CLOSING);
        $this->store->recordSuccess('boursorama');
        $this->clock->modify('2026-08-10 09:05:00');

        $boursorama = self::freshnessOf($this->createChecker('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertSame(300, $boursorama->secondsSinceWindowOpened);
        self::assertSame(228000, $boursorama->ageInSeconds);
        self::assertFalse($boursorama->isStale());
    }

    public function testAGroupIsStaleAgainOnceItsWindowHasBeenOpenLongEnough(): void
    {
        $this->useMarketClockAt(self::FRIDAY_CLOSING);
        $this->store->recordSuccess('boursorama');
        $this->clock->modify('2026-08-10 10:00:00');

        $boursorama = self::freshnessOf($this->createChecker('syncs-active-window.yaml')->checkEnabledSyncGroups(), 'boursorama');

        self::assertSame(3600, $boursorama->secondsSinceWindowOpened);
        self::assertTrue($boursorama->isStale());
    }

    private function createChecker(string $fixtureName): SyncHealthChecker
    {
        return new SyncHealthChecker(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName),
            $this->store,
            $this->clock,
        );
    }

    private function useMarketClockAt(string $instant): void
    {
        $this->clock = new MockClock($instant, self::MARKET_TIMEZONE);
        $this->store = new LastSuccessfulSyncStore(new ArrayAdapter(), $this->clock);
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
