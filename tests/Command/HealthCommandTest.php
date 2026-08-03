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

    private function createTester(string $fixtureName): CommandTester
    {
        $syncHealthChecker = new SyncHealthChecker(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName),
            $this->store,
        );

        return new CommandTester(new HealthCommand($syncHealthChecker));
    }
}
