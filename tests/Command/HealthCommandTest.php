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

    public function testAFreshSyncGroupExitsSuccessfully(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);
        $store->recordSuccess('weather');
        $clock->modify('+12 minutes');

        $tester = self::createTester(self::FIXTURES_DIR.'/syncs-valid.yaml', $store, $clock);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('weather: last push 12 min ago, stale after 90 min', $tester->getDisplay());
    }

    public function testAStaleSyncGroupExitsWithAFailure(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);
        $store->recordSuccess('weather');
        $clock->modify('+91 minutes');

        $tester = self::createTester(self::FIXTURES_DIR.'/syncs-valid.yaml', $store, $clock);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No recent push for: weather', $tester->getDisplay());
    }

    public function testASyncGroupThatNeverPushedExitsWithAFailure(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $tester = self::createTester(self::FIXTURES_DIR.'/syncs-valid.yaml', $store, $clock);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('weather: never pushed to the device', $tester->getDisplay());
        self::assertStringContainsString('No recent push for: weather', $tester->getDisplay());
    }

    public function testAnUnreadableConfigurationIsReportedApartFromAStalePush(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $tester = self::createTester(self::FIXTURES_DIR.'/does-not-exist.yaml', $store, $clock);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('The configuration cannot be read', $tester->getDisplay());
        self::assertStringNotContainsString('No recent push for', $tester->getDisplay());
    }

    public function testNoEnabledSyncGroupExitsSuccessfully(): void
    {
        $clock = new MockClock('2026-08-03 10:00:00');
        $store = new LastSuccessfulSyncStore(new ArrayAdapter(), $clock);

        $tester = self::createTester(self::FIXTURES_DIR.'/syncs-all-disabled.yaml', $store, $clock);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No sync group is enabled', $tester->getDisplay());
    }

    private static function createTester(
        string $configFilePath,
        LastSuccessfulSyncStore $store,
        MockClock $clock,
    ): CommandTester {
        $syncHealthChecker = new SyncHealthChecker(
            SyncsConfigLoaderFactory::forConfigFile($configFilePath),
            $store,
            $clock,
        );

        return new CommandTester(new HealthCommand($syncHealthChecker));
    }
}
