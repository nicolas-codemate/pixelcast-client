<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HealthCommand;
use App\Tests\Factory\SyncHealthScenario;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class HealthCommandTest extends TestCase
{
    private SyncHealthScenario $scenario;

    protected function setUp(): void
    {
        $this->scenario = new SyncHealthScenario();
    }

    public function testAFreshSyncGroupExitsSuccessfully(): void
    {
        $this->scenario->store->recordSuccess('weather');
        $this->scenario->clock->modify('+12 minutes');

        $tester = $this->createTester('syncs-valid.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('weather: last push 12 min ago, stale after 90 min', $tester->getDisplay());
    }

    public function testAStaleSyncGroupExitsWithAFailure(): void
    {
        $this->scenario->store->recordSuccess('weather');
        $this->scenario->clock->modify('+91 minutes');

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
        $this->scenario->useMarketClockAt(SyncHealthScenario::SATURDAY_NOON);
        $this->scenario->store->recordSuccess('weather');

        $tester = $this->createTester('syncs-active-window.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('boursorama: outside its active window, not watched', $tester->getDisplay());
        self::assertStringNotContainsString('No recent push for', $tester->getDisplay());
    }

    public function testAGroupJudgedFromItsReopeningSaysSoOnItsLine(): void
    {
        $this->scenario->useMarketClockAt(SyncHealthScenario::FRIDAY_CLOSING);
        $this->scenario->store->recordSuccess('boursorama');
        $this->scenario->clock->modify('2026-08-10 09:05:00');
        $this->scenario->store->recordSuccess('weather');

        $tester = $this->createTester('syncs-active-window.yaml');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('boursorama: last push 3800 min ago, window reopened 5 min ago, stale after 45 min', $tester->getDisplay());
    }

    private function createTester(string $fixtureName): CommandTester
    {
        return new CommandTester(new HealthCommand($this->scenario->checkerFor($fixtureName)));
    }
}
