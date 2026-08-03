<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Health\LastSuccessfulSyncStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LastSuccessfulSyncStoreTest extends TestCase
{
    private const string PUSH_INSTANT = '2026-08-03 10:00:00';

    private MockClock $clock;
    private LastSuccessfulSyncStore $store;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::PUSH_INSTANT);
        $this->store = new LastSuccessfulSyncStore(new ArrayAdapter(), $this->clock);
    }

    public function testASyncTypeWithoutAnyRecordedSuccessHasNoAge(): void
    {
        self::assertNull($this->store->ageInSecondsOf('weather'));
    }

    public function testARecordedSuccessAgesWithTheClock(): void
    {
        $this->store->recordSuccess('weather');
        $this->clock->modify('+30 minutes');

        self::assertSame(1800, $this->store->ageInSecondsOf('weather'));
    }

    public function testANewSuccessReplacesThePreviousOne(): void
    {
        $this->store->recordSuccess('weather');
        $this->clock->modify('+30 minutes');
        $this->store->recordSuccess('weather');

        self::assertSame(0, $this->store->ageInSecondsOf('weather'));
    }

    public function testTwoSyncTypesAreRecordedIndependently(): void
    {
        $this->store->recordSuccess('weather');
        $this->clock->modify('+15 minutes');
        $this->store->recordSuccess('coingecko');

        self::assertSame(900, $this->store->ageInSecondsOf('weather'));
        self::assertSame(0, $this->store->ageInSecondsOf('coingecko'));
    }
}
