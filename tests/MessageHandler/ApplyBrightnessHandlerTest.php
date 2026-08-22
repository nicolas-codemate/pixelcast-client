<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Exception\DeviceBusyException;
use App\Client\Settings\BrightnessLevel;
use App\Message\ApplyBrightnessMessage;
use App\MessageHandler\ApplyBrightnessHandler;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The fixture holds the panel at 120 from 07:00 to 22:00 every day, drops it to 20 from 22:00 to
 * 07:00 on the working week only, and keeps 200 the rest of the time.
 */
final class ApplyBrightnessHandlerTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string BRIGHTNESS_WINDOWS_CONFIG_FILE = 'syncs-device-brightness-windows.yaml';
    private const string CONFIG_FILE_WITHOUT_WINDOWS = 'syncs-valid.yaml';
    private const string DEVICE_TIMEZONE = 'Europe/Paris';
    private const string MONDAY_MORNING = '2026-08-03 10:00:00';
    private const string SATURDAY_NIGHT = '2026-08-08 23:00:00';

    public function testTheLevelOfTheWindowCoveringTheInstantIsPushed(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(self::BRIGHTNESS_WINDOWS_CONFIG_FILE, $client, new MockClock(self::MONDAY_MORNING, self::DEVICE_TIMEZONE), $logger);

        $handler(new ApplyBrightnessMessage());

        self::assertSame([120], self::pushedLevelsOf($client));
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(120, $logger->records[0]['context']['level'] ?? null);
    }

    public function testOutsideEveryWindowTheDefaultLevelIsPushed(): void
    {
        $client = new RecordingPixelcastClientStub();
        $handler = self::buildHandler(self::BRIGHTNESS_WINDOWS_CONFIG_FILE, $client, new MockClock(self::SATURDAY_NIGHT, self::DEVICE_TIMEZONE));

        $handler(new ApplyBrightnessMessage());

        self::assertSame([200], self::pushedLevelsOf($client));
    }

    public function testTwoTicksInsideTheSameWindowPushOnlyOnce(): void
    {
        $client = new RecordingPixelcastClientStub();
        $clock = new MockClock(self::MONDAY_MORNING, self::DEVICE_TIMEZONE);
        $handler = self::buildHandler(self::BRIGHTNESS_WINDOWS_CONFIG_FILE, $client, $clock);

        $handler(new ApplyBrightnessMessage());
        $clock->modify('2026-08-03 10:01:00');
        $handler(new ApplyBrightnessMessage());

        self::assertSame([120], self::pushedLevelsOf($client));
    }

    public function testCrossingTheBoundOfAWindowPushesTheNewLevel(): void
    {
        $client = new RecordingPixelcastClientStub();
        $clock = new MockClock('2026-08-03 21:59:00', self::DEVICE_TIMEZONE);
        $handler = self::buildHandler(self::BRIGHTNESS_WINDOWS_CONFIG_FILE, $client, $clock);

        $handler(new ApplyBrightnessMessage());
        $clock->modify('2026-08-03 22:00:00');
        $handler(new ApplyBrightnessMessage());

        self::assertSame([120, 20], self::pushedLevelsOf($client));
    }

    public function testAFileDeclaringNoWindowPushesNothing(): void
    {
        $client = new RecordingPixelcastClientStub();
        $handler = self::buildHandler(self::CONFIG_FILE_WITHOUT_WINDOWS, $client, new MockClock(self::MONDAY_MORNING, self::DEVICE_TIMEZONE));

        $handler(new ApplyBrightnessMessage());

        self::assertSame([], self::pushedLevelsOf($client));
    }

    public function testADeviceFailureIsLoggedAndTheNextTickTriesTheSameLevelAgain(): void
    {
        $deviceFailure = DeviceBusyException::slotExhausted('/brightness');
        $client = new RecordingPixelcastClientStub($deviceFailure);
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(self::BRIGHTNESS_WINDOWS_CONFIG_FILE, $client, new MockClock(self::MONDAY_MORNING, self::DEVICE_TIMEZONE), $logger);

        $handler(new ApplyBrightnessMessage());
        $handler(new ApplyBrightnessMessage());

        self::assertSame([], self::pushedLevelsOf($client));
        self::assertCount(2, $logger->records, 'a level that failed to reach the device is tried again rather than held as pushed');
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($deviceFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    /**
     * @return list<int>
     */
    private static function pushedLevelsOf(RecordingPixelcastClientStub $client): array
    {
        return array_map(
            static fn (BrightnessLevel $brightness): int => $brightness->level,
            $client->pushedBrightnessLevels,
        );
    }

    private static function buildHandler(
        string $configFileName,
        RecordingPixelcastClientStub $client,
        ClockInterface $clock,
        LoggerInterface $logger = new NullLogger(),
    ): ApplyBrightnessHandler {
        return new ApplyBrightnessHandler(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
            $client,
            $clock,
            $logger,
        );
    }
}
