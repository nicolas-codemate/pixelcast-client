<?php

declare(strict_types=1);

namespace App\Tests\Config\Device;

use App\Config\Device\DeviceConfig;
use App\Config\Exception\PixelCastConfigException;
use PHPUnit\Framework\TestCase;

final class DeviceConfigTest extends TestCase
{
    private const string REFERENCE_INSTANT = '2026-06-15 12:00:00';

    public function testASectionCarryingOnlyATimezonePushesTheDerivedPosixTimezone(): void
    {
        $deviceConfig = self::deviceConfigOf(['timezone' => 'Europe/Paris']);

        self::assertNotNull($deviceConfig);
        self::assertSame(
            ['ntp' => ['tz_posix' => 'CET-1CEST,M3.5.0,M10.5.0/3']],
            $deviceConfig->toSettingsPayload(self::referenceInstant())->toArray(),
        );
    }

    public function testEverySettingOfTheSectionReachesThePayload(): void
    {
        $deviceConfig = self::deviceConfigOf([
            'timezone' => 'Europe/Paris',
            'brightness' => 120,
            'autoRotate' => true,
            'defaultDuration' => 8000,
            'weatherDuration' => 12000,
            'ntp' => ['server' => 'pool.ntp.org'],
        ]);

        self::assertNotNull($deviceConfig);
        self::assertSame([
            'brightness' => 120,
            'autoRotate' => true,
            'defaultDuration' => 8000,
            'weatherDuration' => 12000,
            'ntp' => ['server' => 'pool.ntp.org', 'tz_posix' => 'CET-1CEST,M3.5.0,M10.5.0/3'],
        ], $deviceConfig->toSettingsPayload(self::referenceInstant())->toArray());
    }

    public function testASectionWithoutATimezoneNorAnNtpServerPushesNoNtpSettings(): void
    {
        $deviceConfig = self::deviceConfigOf(['brightness' => 10]);

        self::assertNotNull($deviceConfig);
        self::assertSame(['brightness' => 10], $deviceConfig->toSettingsPayload(self::referenceInstant())->toArray());
    }

    public function testAFileWithoutADeviceSectionCarriesNoConfig(): void
    {
        self::assertNull(DeviceConfig::optionalFromConfigTree(['syncs' => []]));
    }

    public function testABrightnessAboveTheLevelsTheDeviceAcceptsIsRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('device.brightness');

        self::deviceConfigOf(['brightness' => 300]);
    }

    public function testAWeatherDurationBelowTheBoundIsRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('device.weatherDuration');

        self::deviceConfigOf(['weatherDuration' => 1000]);
    }

    public function testDeclaredBrightnessWindowsBecomeAScheduleKeepingTheSectionLevelAsItsDefault(): void
    {
        $deviceConfig = self::deviceConfigOf([
            'timezone' => 'Europe/Paris',
            'brightness' => 200,
            'brightnessWindows' => [
                ['from' => '22:00', 'to' => '07:00', 'level' => 20],
                ['from' => '12:00', 'to' => '14:00', 'level' => 255, 'days' => ['sat', 'sun']],
            ],
        ]);

        self::assertNotNull($deviceConfig);
        self::assertNotNull($deviceConfig->brightnessSchedule);
        self::assertSame('default 200 22:00-07:00@20+12:00-14:00@255 Europe/Paris', (string) $deviceConfig->brightnessSchedule);
    }

    public function testASectionWithoutBrightnessWindowsCarriesNoSchedule(): void
    {
        $deviceConfig = self::deviceConfigOf(['timezone' => 'Europe/Paris', 'brightness' => 200]);

        self::assertNotNull($deviceConfig);
        self::assertNull($deviceConfig->brightnessSchedule);
    }

    public function testBrightnessWindowsWithoutALevelToFallBackOnAreRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('declare "device.brightness"');

        self::deviceConfigOf([
            'timezone' => 'Europe/Paris',
            'brightnessWindows' => [['from' => '22:00', 'to' => '07:00', 'level' => 20]],
        ]);
    }

    public function testBrightnessWindowsWithoutATimezoneToReadTheirHoursInAreRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('declare "device.timezone"');

        self::deviceConfigOf([
            'brightness' => 200,
            'brightnessWindows' => [['from' => '22:00', 'to' => '07:00', 'level' => 20]],
        ]);
    }

    public function testAWindowLevelAboveTheLevelsTheDeviceAcceptsIsRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('device.brightnessWindows[0].level');

        self::deviceConfigOf([
            'timezone' => 'Europe/Paris',
            'brightness' => 200,
            'brightnessWindows' => [['from' => '22:00', 'to' => '07:00', 'level' => 300]],
        ]);
    }

    public function testAWindowWithoutALevelIsRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('device.brightnessWindows[0].level');

        self::deviceConfigOf([
            'timezone' => 'Europe/Paris',
            'brightness' => 200,
            'brightnessWindows' => [['from' => '22:00', 'to' => '07:00']],
        ]);
    }

    public function testAWindowOpeningAndClosingAtTheSameMinuteIsRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('device.brightnessWindows[0].to');

        self::deviceConfigOf([
            'timezone' => 'Europe/Paris',
            'brightness' => 200,
            'brightnessWindows' => [['from' => '22:00', 'to' => '22:00', 'level' => 20]],
        ]);
    }

    public function testAnEmptyNtpServerIsRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('device.ntp.server');

        self::deviceConfigOf(['ntp' => ['server' => '  ']]);
    }

    /**
     * @param array<string, mixed> $deviceOptions
     */
    private static function deviceConfigOf(array $deviceOptions): ?DeviceConfig
    {
        return DeviceConfig::optionalFromConfigTree([DeviceConfig::OPTION_KEY => $deviceOptions]);
    }

    private static function referenceInstant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::REFERENCE_INSTANT, new \DateTimeZone('UTC'));
    }
}
