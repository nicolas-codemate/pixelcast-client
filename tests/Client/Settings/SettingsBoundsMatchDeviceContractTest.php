<?php

declare(strict_types=1);

namespace App\Tests\Client\Settings;

use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\NtpSettings;
use App\Client\Settings\SettingsPayload;
use App\Tests\Factory\SchemaPropertyReader;
use PHPUnit\Framework\TestCase;

/**
 * The settings the client refuses to send are bounded twice: by its own constants, and by the device
 * contract sync/schemas/settings.yaml copies from the firmware repository. A firmware bump re-fetched
 * by `make sync-api` must not leave the constants behind.
 */
final class SettingsBoundsMatchDeviceContractTest extends TestCase
{
    private const string SETTINGS_CONTRACT_FILE = 'settings.yaml';

    public function testTheDeviceContractBoundsTheBrightnessToTheLevelsTheClientEnforces(): void
    {
        $brightnessSchema = self::settingsUpdateProperties()['brightness'];

        self::assertSame(BrightnessLevel::MINIMUM_LEVEL, $brightnessSchema['minimum']);
        self::assertSame(BrightnessLevel::MAXIMUM_LEVEL, $brightnessSchema['maximum']);
    }

    public function testTheDeviceContractBoundsTheWeatherDurationToTheMillisecondsTheClientEnforces(): void
    {
        $weatherDurationSchema = self::settingsUpdateProperties()['weatherDuration'];

        self::assertSame(SettingsPayload::MINIMUM_WEATHER_DURATION_MILLISECONDS, $weatherDurationSchema['minimum']);
        self::assertSame(SettingsPayload::MAXIMUM_WEATHER_DURATION_MILLISECONDS, $weatherDurationSchema['maximum']);
    }

    public function testTheDeviceContractBoundsThePosixTimezoneToTheCharactersTheClientEnforces(): void
    {
        $ntpSchema = self::settingsUpdateProperties()['ntp'];
        self::assertIsArray($ntpSchema['properties']);
        $timezoneSchema = SchemaPropertyReader::asPropertyMap($ntpSchema['properties'])['tz_posix'];

        self::assertSame(NtpSettings::MAXIMUM_TIMEZONE_LENGTH, $timezoneSchema['maxLength']);
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function settingsUpdateProperties(): array
    {
        return SchemaPropertyReader::deviceContractPropertiesOf(self::SETTINGS_CONTRACT_FILE, 'SettingsUpdateRequest');
    }
}
