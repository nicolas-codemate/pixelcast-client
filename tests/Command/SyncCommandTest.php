<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncCommand;
use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\Message\SyncWeatherMessage;
use App\Schedule;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\CapturingMessageBusStub;
use App\Tests\Stub\PlainSyncMessageStub;
use App\Tests\Stub\StaticSyncMessageRegistryStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string GROUP_WINDOW_CONFIG_FILE = 'syncs-active-window.yaml';
    private const string ITEM_WINDOW_CONFIG_FILE = 'syncs-item-active-window.yaml';
    private const string SLEEPING_CONFIG_FILE = 'syncs-with-sleep.yaml';

    private CapturingMessageBusStub $messageBus;

    public function testEmptyRegistryWarnsAndDispatchesNothing(): void
    {
        $tester = $this->createTester([]);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No sync type is registered', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testEmptyRegistryStillRejectsAnUnknownType(): void
    {
        $tester = $this->createTester([]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Available: none', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testEmptyRegistryStillRejectsATypeCombinedWithAll(): void
    {
        $tester = $this->createTester([]);

        $exitCode = $tester->execute(['type' => 'weather', '--all' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('not both', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testEmptyRegistryWithAllWarnsAndDispatchesNothing(): void
    {
        $tester = $this->createTester([]);

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No sync type is registered', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testDirectTypeDispatchesOnlyThatMessage(): void
    {
        $weatherMessage = new PlainSyncMessageStub();
        $trackersMessage = new PlainSyncMessageStub();
        $tester = $this->createTester(['weather' => $weatherMessage, 'trackers' => $trackersMessage]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$weatherMessage], $this->messageBus->dispatchedMessages);
    }

    public function testUnknownTypeIsRejectedAndListsAvailableTypes(): void
    {
        $tester = $this->createTester(['weather' => new PlainSyncMessageStub(), 'trackers' => new PlainSyncMessageStub()]);

        $exitCode = $tester->execute(['type' => 'nope']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('weather, trackers', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testAllDispatchesEveryMessageInRegistryOrder(): void
    {
        $weatherMessage = new PlainSyncMessageStub();
        $trackersMessage = new PlainSyncMessageStub();
        $tester = $this->createTester(['weather' => $weatherMessage, 'trackers' => $trackersMessage]);

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$weatherMessage, $trackersMessage], $this->messageBus->dispatchedMessages);
    }

    public function testAllCombinedWithATypeIsRejected(): void
    {
        $tester = $this->createTester(['weather' => new PlainSyncMessageStub(), 'trackers' => new PlainSyncMessageStub()]);

        $exitCode = $tester->execute(['type' => 'weather', '--all' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('not both', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testInteractiveChoiceDispatchesOnlyTheSelectedMessage(): void
    {
        $weatherMessage = new PlainSyncMessageStub();
        $trackersMessage = new PlainSyncMessageStub();
        $tester = $this->createTester(['weather' => $weatherMessage, 'trackers' => $trackersMessage]);
        $tester->setInputs(['trackers']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$trackersMessage], $this->messageBus->dispatchedMessages);
    }

    public function testMissingTypeInNonInteractiveModeIsRejected(): void
    {
        $tester = $this->createTester(['weather' => new PlainSyncMessageStub(), 'trackers' => new PlainSyncMessageStub()]);

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('No sync type given', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testDispatchWithoutHandledStampReportsAnUnobservableResult(): void
    {
        $tester = $this->createTester(['weather' => new PlainSyncMessageStub()]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('weather: dispatched, the result is not observable from this process', $tester->getDisplay());
    }

    public function testPushedOutcomeIsReportedAndSucceeds(): void
    {
        $tester = $this->createTester(['weather' => new PlainSyncMessageStub()], [SyncOutcome::Pushed]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('weather: pushed to the device', $tester->getDisplay());
    }

    public function testSkippedOutcomeIsReportedAndFails(): void
    {
        $tester = $this->createTester(['weather' => new PlainSyncMessageStub()], [SyncOutcome::Skipped]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('weather: skipped, nothing to push', $tester->getDisplay());
        self::assertStringContainsString('Nothing reached the device for: weather', $tester->getDisplay());
    }

    public function testFailedOutcomeIsReportedAndFails(): void
    {
        $tester = $this->createTester(['weather' => new PlainSyncMessageStub()], [SyncOutcome::Failed]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('weather: failed, see the logs', $tester->getDisplay());
    }

    public function testAllReportsEveryOutcomeAndFailsOnTheFailingTypeOnly(): void
    {
        $tester = $this->createTester(
            ['weather' => new PlainSyncMessageStub(), 'trackers' => new PlainSyncMessageStub()],
            [SyncOutcome::Pushed, SyncOutcome::Failed],
        );

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('weather: pushed to the device', $tester->getDisplay());
        self::assertStringContainsString('trackers: failed, see the logs', $tester->getDisplay());
        self::assertStringContainsString('Nothing reached the device for: trackers', $tester->getDisplay());
    }

    public function testAGroupOutsideItsActiveWindowCanStillBeDispatchedByHand(): void
    {
        $tester = $this->createTesterForConfigFile(self::GROUP_WINDOW_CONFIG_FILE, [SyncOutcome::Pushed]);

        $exitCode = $tester->execute(['type' => 'boursorama']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertEquals(
            [new SyncTrackerMessage('boursorama', honoursActiveWindows: false)],
            $this->messageBus->dispatchedMessages,
        );
    }

    public function testAnItemOutsideItsOwnActiveWindowIsStillDispatchedByHand(): void
    {
        $tester = $this->createTesterForConfigFile(self::ITEM_WINDOW_CONFIG_FILE, [SyncOutcome::Pushed]);

        $exitCode = $tester->execute(['type' => 'boursorama']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertEquals(
            [new SyncTrackerMessage('boursorama', honoursActiveWindows: false)],
            $this->messageBus->dispatchedMessages,
        );
    }

    public function testAGroupSuspendedByTheSleepWindowCanStillBeDispatchedByHand(): void
    {
        $tester = $this->createTesterForConfigFile(self::SLEEPING_CONFIG_FILE, [SyncOutcome::Pushed]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertEquals([new SyncWeatherMessage()], $this->messageBus->dispatchedMessages);
    }

    public function testAllDispatchesEveryGroupAsAManualRunToo(): void
    {
        $tester = $this->createTesterForConfigFile(self::GROUP_WINDOW_CONFIG_FILE, [SyncOutcome::Pushed, SyncOutcome::Pushed]);

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertEquals(
            [new SyncWeatherMessage(), new SyncTrackerMessage('boursorama', honoursActiveWindows: false)],
            $this->messageBus->dispatchedMessages,
        );
    }

    /**
     * @param array<string, PlainSyncMessageStub> $syncMessages
     * @param list<SyncOutcome>|null $outcomesInDispatchOrder
     */
    private function createTester(array $syncMessages, ?array $outcomesInDispatchOrder = null): CommandTester
    {
        $this->messageBus = new CapturingMessageBusStub($outcomesInDispatchOrder);

        return new CommandTester(
            new SyncCommand($this->messageBus, new StaticSyncMessageRegistryStub($syncMessages)),
        );
    }

    /**
     * @param list<SyncOutcome>|null $outcomesInDispatchOrder
     */
    private function createTesterForConfigFile(string $configFileName, ?array $outcomesInDispatchOrder = null): CommandTester
    {
        $this->messageBus = new CapturingMessageBusStub($outcomesInDispatchOrder);
        $schedule = new Schedule(
            new ArrayAdapter(),
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
        );

        return new CommandTester(new SyncCommand($this->messageBus, $schedule));
    }
}
