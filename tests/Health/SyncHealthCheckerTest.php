<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Health\LastSuccessfulSyncStore;
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

    private function createChecker(string $fixtureName): SyncHealthChecker
    {
        return new SyncHealthChecker(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName),
            $this->store,
        );
    }
}
