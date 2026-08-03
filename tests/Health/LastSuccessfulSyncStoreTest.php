<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Health\LastSuccessfulSyncStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LastSuccessfulSyncStoreTest extends TestCase
{
    public function testASyncTypeWithoutAnyRecordedSuccessReadsBackAsNull(): void
    {
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock('2026-08-03 10:00:00'));

        self::assertNull($store->lastSuccessAt('weather'));
    }

    public function testARecordedSuccessReadsBackAtTheInstantOfTheClock(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $store->recordSuccess('weather');

        self::assertSame($clock->now()->getTimestamp(), $store->lastSuccessAt('weather')?->getTimestamp());
    }

    public function testANewSuccessReplacesThePreviousOne(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $store->recordSuccess('weather');
        $clock->modify('+30 minutes');
        $store->recordSuccess('weather');

        self::assertSame($clock->now()->getTimestamp(), $store->lastSuccessAt('weather')?->getTimestamp());
    }

    public function testTwoSyncTypesAreRecordedIndependently(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $store->recordSuccess('weather');
        $clock->modify('+15 minutes');
        $store->recordSuccess('coingecko');

        $weatherSuccessAt = $store->lastSuccessAt('weather');
        $coingeckoSuccessAt = $store->lastSuccessAt('coingecko');

        self::assertNotNull($weatherSuccessAt);
        self::assertNotNull($coingeckoSuccessAt);
        self::assertSame(900, $coingeckoSuccessAt->getTimestamp() - $weatherSuccessAt->getTimestamp());
    }
}
