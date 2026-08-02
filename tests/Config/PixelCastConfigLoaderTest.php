<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Exception\PixelCastConfigException;
use App\Config\PixelCastConfig;
use App\Config\PixelCastConfigLoader;
use PHPUnit\Framework\TestCase;

final class PixelCastConfigLoaderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';

    public function testLoadReturnsConfigFromValidFile(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/valid.yaml');

        $config = $loader->load();

        self::assertSame(120, $config->weatherInterval);
        self::assertSame(30, $config->trackerInterval);
        self::assertSame('openmeteo', $config->weatherSource);
        self::assertSame('yahoo-finance', $config->trackerSource);
        self::assertSame(48.8566, $config->weatherLatitude);
        self::assertSame(2.3522, $config->weatherLongitude);
        self::assertSame('metric', $config->weatherUnits);
        self::assertSame('fr', $config->weatherLocale);
    }

    public function testLoadThrowsWhenFileMissing(): void
    {
        $missingPath = self::FIXTURES_DIR.'/does-not-exist.yaml';
        $loader = new PixelCastConfigLoader($missingPath);

        self::assertFalse($loader->exists());
        self::assertSame($missingPath, $loader->filePath());

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('not found');

        $loader->load();
    }

    public function testLoadThrowsOnSyntaxError(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/invalid-syntax.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('Failed to parse PixelCast config');

        $loader->load();
    }

    public function testLoadThrowsOnMissingKey(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/missing-key.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('tracked_assets');

        $loader->load();
    }

    public function testLoadThrowsWhenLatitudeIsOutOfBounds(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/invalid-weather-latitude.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('weather_latitude');

        $loader->load();
    }

    public function testLoadThrowsWhenWeatherUnitsIsNotAllowed(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/invalid-weather-units.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('expected one of: metric, imperial');

        $loader->load();
    }

    public function testLoadThrowsWhenWeatherLocaleIsNotAllowed(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/invalid-weather-locale.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('expected one of: fr, en');

        $loader->load();
    }

    public function testLoadParsesTrackedAssetsAsTrimmedList(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/valid.yaml');

        $config = $loader->load();

        self::assertSame(['BTC', 'AAPL', 'SPY', 'ETH'], $config->trackedAssets);
    }

    public function testToRawMapJoinsTrackedAssetsWithCommaSpace(): void
    {
        $config = new PixelCastConfig(
            weatherInterval: 120,
            trackerInterval: 30,
            trackedAssets: ['BTC', 'AAPL', 'SPY', 'ETH'],
            weatherSource: 'openmeteo',
            trackerSource: 'yahoo-finance',
            weatherLatitude: 48.8566,
            weatherLongitude: 2.3522,
            weatherUnits: 'metric',
            weatherLocale: 'fr',
        );

        $rawMap = $config->toRawMap();

        self::assertSame('BTC, AAPL, SPY, ETH', $rawMap['tracked_assets']);
        self::assertSame(120, $rawMap['weather_interval']);
        self::assertSame('openmeteo', $rawMap['weather_source']);
        self::assertSame(48.8566, $rawMap['weather_latitude']);
        self::assertSame('fr', $rawMap['weather_locale']);
    }
}
