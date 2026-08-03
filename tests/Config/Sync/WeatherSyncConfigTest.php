<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\WeatherSyncConfig;
use App\Config\WeatherLocale;
use App\Config\WeatherUnits;
use App\Message\SyncWeatherMessage;
use PHPUnit\Framework\TestCase;

final class WeatherSyncConfigTest extends TestCase
{
    private const string OPTIONS_PATH = 'syncs.weather';

    /**
     * @return array<string, mixed>
     */
    private static function validOptions(): array
    {
        return [
            'enabled' => true,
            'interval' => '30 minutes',
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'units' => 'metric',
            'locale' => 'fr',
        ];
    }

    public function testTheSyncTypeIsTheKeyUsedInTheConfigurationFile(): void
    {
        self::assertSame('weather', WeatherSyncConfig::syncType());
    }

    public function testAValidOptionMapIsHydrated(): void
    {
        $weatherSync = WeatherSyncConfig::fromOptions(self::validOptions(), self::OPTIONS_PATH);

        self::assertTrue($weatherSync->enabled);
        self::assertSame('30 minutes', $weatherSync->interval->expression);
        self::assertSame(48.8566, $weatherSync->latitude);
        self::assertSame(2.3522, $weatherSync->longitude);
        self::assertSame(WeatherUnits::Metric, $weatherSync->units);
        self::assertSame(WeatherLocale::French, $weatherSync->locale);
    }

    public function testTheGroupIsTriggeredByAWeatherSyncMessage(): void
    {
        $weatherSync = WeatherSyncConfig::fromOptions(self::validOptions(), self::OPTIONS_PATH);

        self::assertInstanceOf(SyncWeatherMessage::class, $weatherSync->syncMessage());
    }

    public function testAnIntervalTheSchedulerCannotParseNamesTheOption(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.interval');

        WeatherSyncConfig::fromOptions(array_merge(self::validOptions(), ['interval' => 'every fortnight']), self::OPTIONS_PATH);
    }

    public function testAMissingOptionNamesItsFullPath(): void
    {
        $options = self::validOptions();
        unset($options['latitude']);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.latitude');

        WeatherSyncConfig::fromOptions($options, self::OPTIONS_PATH);
    }

    public function testAnUnknownUnitsValueNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.units');

        WeatherSyncConfig::fromOptions(array_merge(self::validOptions(), ['units' => 'kelvin']), self::OPTIONS_PATH);
    }
}
