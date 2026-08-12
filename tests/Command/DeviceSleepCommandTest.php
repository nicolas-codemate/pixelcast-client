<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Client\Exception\DeviceUnreachableException;
use App\Client\Sleep\SleepState;
use App\Command\DeviceSleepCommand;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingPixelcastClientStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeviceSleepCommandTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string FILE_WITH_A_SLEEP_SECTION = 'syncs-with-sleep.yaml';
    private const string FILE_WITHOUT_A_SLEEP_SECTION = 'syncs-valid.yaml';

    private string|false $terminalWidthBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();

        // Console blocks wrap at the terminal width, and a width of 80 would fold the messages these
        // assertions read.
        $this->terminalWidthBeforeTheTest = getenv('COLUMNS');
        putenv('COLUMNS=200');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->terminalWidthBeforeTheTest ? 'COLUMNS' : 'COLUMNS='.$this->terminalWidthBeforeTheTest);

        parent::tearDown();
    }

    public function testTheScheduleOfTheFileIsPushedForTheSevenDaysAndTheDeviceIsReadBack(): void
    {
        $client = new RecordingPixelcastClientStub();
        $client->sleepStateToReturn = SleepState::fromResponseBody([
            'sleeping' => true,
            'reason' => 'schedule',
            'config' => [
                'enabled' => true,
                'display_mode' => 'black',
                'schedule' => [
                    'monday' => ['all_day' => false, 'slots' => [['start' => '00:00', 'end' => '07:00']]],
                    'sunday' => ['all_day' => true, 'slots' => []],
                ],
            ],
        ]);

        $tester = self::createTester(self::FILE_WITH_A_SLEEP_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertCount(1, $client->pushedSleepPayloads);
        $pushedPayload = $client->pushedSleepPayloads[0];
        self::assertTrue($pushedPayload->enabled);
        self::assertSame('black', $pushedPayload->displayMode);
        self::assertCount(7, $pushedPayload->sleepSlotsByDayName);

        $mondaySlots = $pushedPayload->sleepSlotsByDayName['monday'];
        self::assertCount(1, $mondaySlots);
        self::assertSame('00:00', $mondaySlots[0]->start);
        self::assertSame('07:00', $mondaySlots[0]->end);

        $display = $tester->getDisplay();
        self::assertStringContainsString('asleep, reason "schedule"', $display);
        self::assertStringContainsString('black', $display);
        self::assertStringContainsString('monday', $display);
        self::assertStringContainsString('00:00-07:00', $display);
        self::assertStringContainsString('all day', $display);
    }

    public function testADeviceRefusingToApplyTheScheduleNamesItsReasonWhileStillAwake(): void
    {
        $client = new RecordingPixelcastClientStub();
        $client->sleepStateToReturn = SleepState::fromResponseBody([
            'sleeping' => false,
            'reason' => 'ntp_not_synced',
            'config' => ['enabled' => true, 'display_mode' => 'black', 'schedule' => []],
        ]);

        $tester = self::createTester(self::FILE_WITH_A_SLEEP_SECTION, $client);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('awake, reason "ntp_not_synced"', $tester->getDisplay());
    }

    public function testAFileWithoutASleepSectionPushesNothingAndNamesTheSection(): void
    {
        $client = new RecordingPixelcastClientStub();
        $tester = self::createTester(self::FILE_WITHOUT_A_SLEEP_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('"sleep" section', $tester->getDisplay());
        self::assertSame([], $client->pushedSleepPayloads);
    }

    public function testADeviceThatCannotBeReachedFailsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub(DeviceUnreachableException::forPath('/sleep', new \RuntimeException('no route to host')));
        $tester = self::createTester(self::FILE_WITH_A_SLEEP_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('no route to host', $tester->getDisplay());
        self::assertSame([], $client->pushedSleepPayloads);
    }

    public function testAReadBackThatFailsWarnsButKeepsTheCommandSuccessful(): void
    {
        $client = new RecordingPixelcastClientStub(sleepStateFailure: DeviceUnreachableException::forPath('/sleep', new \RuntimeException('answered nothing')));
        $tester = self::createTester(self::FILE_WITH_A_SLEEP_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $client->pushedSleepPayloads);

        $display = $tester->getDisplay();
        self::assertStringContainsString('reading the device back failed', $display);
        self::assertStringContainsString('answered nothing', $display);
    }

    private static function createTester(string $fixtureFileName, RecordingPixelcastClientStub $client): CommandTester
    {
        $syncsConfigLoader = SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureFileName);

        return new CommandTester(new DeviceSleepCommand($syncsConfigLoader, $client));
    }
}
