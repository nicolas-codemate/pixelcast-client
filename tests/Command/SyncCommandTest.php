<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncCommand;
use App\Tests\Stub\CapturingMessageBusStub;
use App\Tests\Stub\StaticSyncMessageRegistryStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    private CapturingMessageBusStub $messageBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messageBus = new CapturingMessageBusStub();
    }

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
        $weatherMessage = new \stdClass();
        $trackersMessage = new \stdClass();
        $tester = $this->createTester(['weather' => $weatherMessage, 'trackers' => $trackersMessage]);

        $exitCode = $tester->execute(['type' => 'weather']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$weatherMessage], $this->messageBus->dispatchedMessages);
    }

    public function testUnknownTypeIsRejectedAndListsAvailableTypes(): void
    {
        $tester = $this->createTester(['weather' => new \stdClass(), 'trackers' => new \stdClass()]);

        $exitCode = $tester->execute(['type' => 'nope']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('weather, trackers', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testAllDispatchesEveryMessageInRegistryOrder(): void
    {
        $weatherMessage = new \stdClass();
        $trackersMessage = new \stdClass();
        $tester = $this->createTester(['weather' => $weatherMessage, 'trackers' => $trackersMessage]);

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$weatherMessage, $trackersMessage], $this->messageBus->dispatchedMessages);
    }

    public function testAllCombinedWithATypeIsRejected(): void
    {
        $tester = $this->createTester(['weather' => new \stdClass(), 'trackers' => new \stdClass()]);

        $exitCode = $tester->execute(['type' => 'weather', '--all' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('not both', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testInteractiveChoiceDispatchesOnlyTheSelectedMessage(): void
    {
        $weatherMessage = new \stdClass();
        $trackersMessage = new \stdClass();
        $tester = $this->createTester(['weather' => $weatherMessage, 'trackers' => $trackersMessage]);
        $tester->setInputs(['trackers']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$trackersMessage], $this->messageBus->dispatchedMessages);
    }

    public function testMissingTypeInNonInteractiveModeIsRejected(): void
    {
        $tester = $this->createTester(['weather' => new \stdClass(), 'trackers' => new \stdClass()]);

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('No sync type given', $tester->getDisplay());
        self::assertSame([], $this->messageBus->dispatchedMessages);
    }

    /**
     * @param array<string, object> $syncMessages
     */
    private function createTester(array $syncMessages): CommandTester
    {
        return new CommandTester(
            new SyncCommand($this->messageBus, new StaticSyncMessageRegistryStub($syncMessages)),
        );
    }
}
