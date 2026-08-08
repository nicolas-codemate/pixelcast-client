<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Health\LastSuccessfulSyncStore;
use App\Health\SyncHealthChecker;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * The clock and the journal of successful pushes a health test runs against. Moving the clock into
 * the timezone of the market replaces both, since the journal timestamps its entries with the clock.
 */
final class SyncHealthScenario
{
    public const string PUSH_INSTANT = '2026-08-03 10:00:00';
    public const string MARKET_TIMEZONE = 'Europe/Paris';
    public const string FRIDAY_CLOSING = '2026-08-07 17:45:00';
    public const string SATURDAY_NOON = '2026-08-08 12:00:00';

    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';

    public MockClock $clock;
    public LastSuccessfulSyncStore $store;

    public function __construct()
    {
        $this->clock = new MockClock(self::PUSH_INSTANT);
        $this->store = new LastSuccessfulSyncStore(new ArrayAdapter(), $this->clock);
    }

    public function useMarketClockAt(string $instant): void
    {
        $this->clock = new MockClock($instant, self::MARKET_TIMEZONE);
        $this->store = new LastSuccessfulSyncStore(new ArrayAdapter(), $this->clock);
    }

    public function checkerFor(string $fixtureName): SyncHealthChecker
    {
        return new SyncHealthChecker(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName),
            $this->store,
            $this->clock,
        );
    }
}
