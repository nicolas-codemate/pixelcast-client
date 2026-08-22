<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Client\Exception\DeviceUnreachableException;
use App\Client\Settings\SettingsSnapshot;
use App\Command\DeviceSettingsCommand;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingPixelcastClientStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeviceSettingsCommandTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string FILE_WITH_A_DEVICE_SECTION = 'syncs-device-settings.yaml';
    private const string FILE_WITHOUT_A_DEVICE_SECTION = 'syncs-valid.yaml';
    private const string FILE_THE_LOADER_REFUSES = 'syncs-invalid-syntax.yaml';
    private const string RUN_INSTANT = '2026-06-15 12:00:00';

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

    public function testTheSettingsOfTheFileArePushedAndTheDeviceIsReadBack(): void
    {
        $client = new RecordingPixelcastClientStub();
        $client->settingsSnapshotToReturn = SettingsSnapshot::fromResponseBody([
            'brightness' => 120,
            'autoRotate' => true,
            'defaultDuration' => 8000,
            'weatherDuration' => 12000,
            'ntp' => ['server' => 'pool.ntp.org', 'tz_posix' => 'CET-1CEST,M3.5.0,M10.5.0/3'],
        ]);

        $tester = self::createTester(self::FILE_WITH_A_DEVICE_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertCount(1, $client->pushedSettingsPayloads);
        self::assertSame([
            'brightness' => 120,
            'autoRotate' => true,
            'defaultDuration' => 8000,
            'weatherDuration' => 12000,
            'ntp' => ['server' => 'pool.ntp.org', 'tz_posix' => 'CET-1CEST,M3.5.0,M10.5.0/3'],
        ], $client->pushedSettingsPayloads[0]->toArray());

        $display = $tester->getDisplay();
        self::assertStringContainsString('is now on the device', $display);
        self::assertStringContainsString('120', $display);
        self::assertStringContainsString('8000 ms', $display);
        self::assertStringContainsString('pool.ntp.org', $display);
        self::assertStringContainsString('CET-1CEST,M3.5.0,M10.5.0/3', $display);
    }

    public function testAFileWithoutADeviceSectionPushesNothingAndNamesTheSection(): void
    {
        $client = new RecordingPixelcastClientStub();
        $tester = self::createTester(self::FILE_WITHOUT_A_DEVICE_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('"device" section', $tester->getDisplay());
        self::assertSame([], $client->pushedSettingsPayloads);
    }

    public function testAnUnusableConfigurationStopsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub();
        $tester = self::createTester(self::FILE_THE_LOADER_REFUSES, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertSame([], $client->pushedSettingsPayloads);
    }

    public function testADeviceThatCannotBeReachedFailsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub(DeviceUnreachableException::forPath('/settings', new \RuntimeException('no route to host')));
        $tester = self::createTester(self::FILE_WITH_A_DEVICE_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('no route to host', $tester->getDisplay());
        self::assertSame([], $client->pushedSettingsPayloads);
    }

    public function testAReadBackThatFailsWarnsButKeepsTheCommandSuccessful(): void
    {
        $client = new RecordingPixelcastClientStub(settingsSnapshotFailure: DeviceUnreachableException::forPath('/settings', new \RuntimeException('answered nothing')));
        $tester = self::createTester(self::FILE_WITH_A_DEVICE_SECTION, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $client->pushedSettingsPayloads);

        $display = $tester->getDisplay();
        self::assertStringContainsString('reading the device back failed', $display);
        self::assertStringContainsString('answered nothing', $display);
    }

    private static function createTester(string $fixtureFileName, RecordingPixelcastClientStub $client): CommandTester
    {
        $syncsConfigLoader = SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureFileName);
        $clock = new MockClock(new \DateTimeImmutable(self::RUN_INSTANT, new \DateTimeZone('UTC')));

        return new CommandTester(new DeviceSettingsCommand($syncsConfigLoader, $client, $clock));
    }
}
