<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Health\LastSuccessfulSyncStore;
use App\Health\SyncHealthChecker;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncHealthCheckerTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';

    public function testAGroupThatNeverPushedIsStale(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $freshnessPerSyncGroup = self::createChecker('syncs-valid.yaml', $store, $clock)->checkEnabledSyncGroups();

        self::assertCount(1, $freshnessPerSyncGroup);
        self::assertSame('weather', $freshnessPerSyncGroup[0]->syncType);
        self::assertNull($freshnessPerSyncGroup[0]->ageInSeconds);
        self::assertTrue($freshnessPerSyncGroup[0]->isStale());
    }

    public function testAFreshPushIsNotStale(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);
        $store->recordSuccess('weather');
        $clock->modify('+30 minutes');

        $freshnessPerSyncGroup = self::createChecker('syncs-valid.yaml', $store, $clock)->checkEnabledSyncGroups();

        self::assertSame(1800, $freshnessPerSyncGroup[0]->ageInSeconds);
        self::assertFalse($freshnessPerSyncGroup[0]->isStale());
    }

    public function testOneMissedCycleIsNotStale(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);
        $store->recordSuccess('weather');
        $clock->modify('+59 minutes');

        $freshnessPerSyncGroup = self::createChecker('syncs-valid.yaml', $store, $clock)->checkEnabledSyncGroups();

        self::assertFalse($freshnessPerSyncGroup[0]->isStale());
    }

    public function testTwoIntervalsExactlyAreNotStale(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);
        $store->recordSuccess('weather');
        $clock->modify('+60 minutes');

        $freshnessPerSyncGroup = self::createChecker('syncs-valid.yaml', $store, $clock)->checkEnabledSyncGroups();

        self::assertFalse($freshnessPerSyncGroup[0]->isStale());
    }

    public function testTwoMissedCyclesAreStale(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);
        $store->recordSuccess('weather');
        $clock->modify('+91 minutes');

        $freshnessPerSyncGroup = self::createChecker('syncs-valid.yaml', $store, $clock)->checkEnabledSyncGroups();

        self::assertTrue($freshnessPerSyncGroup[0]->isStale());
    }

    public function testEachGroupCarriesItsOwnThreshold(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $freshnessPerSyncGroup = self::createChecker('syncs-trackers-enabled.yaml', $store, $clock)->checkEnabledSyncGroups();

        self::assertSame('weather', $freshnessPerSyncGroup[0]->syncType);
        self::assertSame(5400, $freshnessPerSyncGroup[0]->staleAfterInSeconds);
        self::assertSame('coingecko', $freshnessPerSyncGroup[1]->syncType);
        self::assertSame(900, $freshnessPerSyncGroup[1]->staleAfterInSeconds);
    }

    public function testADisabledGroupIsNotWatched(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);
        $store->recordSuccess('weather');

        $freshnessPerSyncGroup = self::createChecker('syncs-all-disabled.yaml', $store, $clock)->checkEnabledSyncGroups();

        self::assertSame([], $freshnessPerSyncGroup);
    }

    private static function createChecker(
        string $fixtureName,
        LastSuccessfulSyncStore $store,
        MockClock $clock,
    ): SyncHealthChecker {
        return new SyncHealthChecker(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName),
            $store,
            $clock,
        );
    }
}
