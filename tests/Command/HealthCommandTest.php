<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HealthCommand;
use App\Health\LastSuccessfulSyncStore;
use App\Health\SyncHealthChecker;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class HealthCommandTest extends TestCase
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

    public function testAFreshSyncGroupExitsSuccessfully(): void
    {
        $this->store->recordSuccess('weather');
        $this->clock->modify('+12 minutes');

        $tester = $this->createTester('syncs-valid.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('weather: last push 12 min ago, stale after 90 min', $tester->getDisplay());
    }

    public function testAStaleSyncGroupExitsWithAFailure(): void
    {
        $this->store->recordSuccess('weather');
        $this->clock->modify('+91 minutes');

        $tester = $this->createTester('syncs-valid.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No recent push for: weather', $tester->getDisplay());
    }

    public function testASyncGroupThatNeverPushedExitsWithAFailure(): void
    {
        $tester = $this->createTester('syncs-valid.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('weather: never pushed to the device', $tester->getDisplay());
        self::assertStringContainsString('No recent push for: weather', $tester->getDisplay());
    }

    public function testAnUnreadableConfigurationIsReportedApartFromAStalePush(): void
    {
        $tester = $this->createTester('does-not-exist.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('The configuration cannot be read', $tester->getDisplay());
        self::assertStringNotContainsString('No recent push for', $tester->getDisplay());
    }

    public function testNoEnabledSyncGroupExitsSuccessfully(): void
    {
        $tester = $this->createTester('syncs-all-disabled.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No sync group is enabled', $tester->getDisplay());
    }

    public function testAGroupOutsideItsActiveWindowIsPrintedAndDoesNotFailTheCommand(): void
    {
        $this->useMarketClockAt(self::SATURDAY_NOON);
        $this->store->recordSuccess('weather');

        $tester = $this->createTester('syncs-active-window.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('boursorama: outside its active window, not watched', $tester->getDisplay());
        self::assertStringNotContainsString('No recent push for', $tester->getDisplay());
    }

    public function testAGroupJudgedFromItsReopeningSaysSoOnItsLine(): void
    {
        $this->useMarketClockAt(self::FRIDAY_CLOSING);
        $this->store->recordSuccess('boursorama');
        $this->clock->modify('2026-08-10 09:05:00');
        $this->store->recordSuccess('weather');

        $tester = $this->createTester('syncs-active-window.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('boursorama: last push 3800 min ago, window reopened 5 min ago, stale after 45 min', $tester->getDisplay());
    }

    private function createTester(string $fixtureName): CommandTester
    {
        $syncHealthChecker = new SyncHealthChecker(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName),
            $this->store,
            $this->clock,
        );

        return new CommandTester(new HealthCommand($syncHealthChecker));
    }

    private function useMarketClockAt(string $instant): void
    {
        $this->clock = new MockClock($instant, self::MARKET_TIMEZONE);
        $this->store = new LastSuccessfulSyncStore(new ArrayAdapter(), $this->clock);
    }
}
