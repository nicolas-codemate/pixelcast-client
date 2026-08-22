<?php

declare(strict_types=1);

namespace App\Tests\Config\Device;

use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\SettingsPayload;
use App\Config\Device\DeviceConfig;
use App\Tests\Factory\SchemaPropertyReader;
use PHPUnit\Framework\TestCase;

/**
 * The panel settings of the device section are bounded twice: by pixelcast.schema.json, which stops
 * a value before it is read, and by the client classes that build the payload. A file the schema
 * accepts must never be one the client then refuses.
 */
final class DeviceSchemaBoundsMatchClientConstantsTest extends TestCase
{
    private const string DEVICE_DEFINITION = 'device';

    public function testTheSchemaBoundsTheBrightnessToTheLevelsTheClientEnforces(): void
    {
        $brightnessSchema = self::devicePropertiesDeclaredBySchema()['brightness'];

        self::assertSame(BrightnessLevel::MINIMUM_LEVEL, $brightnessSchema['minimum']);
        self::assertSame(BrightnessLevel::MAXIMUM_LEVEL, $brightnessSchema['maximum']);
    }

    public function testTheSchemaBoundsTheWeatherDurationToTheMillisecondsTheClientEnforces(): void
    {
        $weatherDurationSchema = self::devicePropertiesDeclaredBySchema()['weatherDuration'];

        self::assertSame(SettingsPayload::MINIMUM_WEATHER_DURATION_MILLISECONDS, $weatherDurationSchema['minimum']);
        self::assertSame(SettingsPayload::MAXIMUM_WEATHER_DURATION_MILLISECONDS, $weatherDurationSchema['maximum']);
    }

    /**
     * Neither the client nor the device contract bounds this duration, so the only thing the schema
     * has to say about it is that an app shown for no time at all makes no sense.
     */
    public function testTheSchemaRefusesADefaultDurationOfZero(): void
    {
        $defaultDurationSchema = self::devicePropertiesDeclaredBySchema()['defaultDuration'];

        self::assertSame(DeviceConfig::MINIMUM_DEFAULT_DURATION_MILLISECONDS, $defaultDurationSchema['minimum']);
        self::assertArrayNotHasKey('maximum', $defaultDurationSchema);
    }

    public function testTheSchemaRefusesAnEmptyNtpServer(): void
    {
        $ntpSchema = self::devicePropertiesDeclaredBySchema()['ntp'];
        self::assertIsArray($ntpSchema['properties']);
        $serverSchema = SchemaPropertyReader::asPropertyMap($ntpSchema['properties'])['server'];

        self::assertSame(1, $serverSchema['minLength']);
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function devicePropertiesDeclaredBySchema(): array
    {
        return SchemaPropertyReader::clientSchemaPropertiesOf(self::DEVICE_DEFINITION);
    }
}
