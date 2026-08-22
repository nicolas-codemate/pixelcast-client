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
