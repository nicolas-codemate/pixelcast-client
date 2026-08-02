<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Config\PixelCastConfig;
use App\Config\WeatherLocale;
use App\Config\WeatherUnits;

final class PixelCastConfigFactory
{
    public static function validConfig(): PixelCastConfig
    {
        return new PixelCastConfig(
            weatherInterval: 120,
            trackerInterval: 30,
            trackedAssets: ['BTC', 'AAPL', 'SPY', 'ETH'],
            weatherSource: 'openmeteo',
            trackerSource: 'yahoo-finance',
            weatherLatitude: 48.8566,
            weatherLongitude: 2.3522,
            weatherUnits: WeatherUnits::Metric,
            weatherLocale: WeatherLocale::French,
        );
    }

    /**
     * @param list<string>|null $trackedAssets
     */
    public static function copyWith(
        PixelCastConfig $base,
        ?int $weatherInterval = null,
        ?int $trackerInterval = null,
        ?array $trackedAssets = null,
        ?string $weatherSource = null,
        ?string $trackerSource = null,
        ?float $weatherLatitude = null,
        ?float $weatherLongitude = null,
        ?WeatherUnits $weatherUnits = null,
        ?WeatherLocale $weatherLocale = null,
    ): PixelCastConfig {
        return new PixelCastConfig(
            weatherInterval: $weatherInterval ?? $base->weatherInterval,
            trackerInterval: $trackerInterval ?? $base->trackerInterval,
            trackedAssets: $trackedAssets ?? $base->trackedAssets,
            weatherSource: $weatherSource ?? $base->weatherSource,
            trackerSource: $trackerSource ?? $base->trackerSource,
            weatherLatitude: $weatherLatitude ?? $base->weatherLatitude,
            weatherLongitude: $weatherLongitude ?? $base->weatherLongitude,
            weatherUnits: $weatherUnits ?? $base->weatherUnits,
            weatherLocale: $weatherLocale ?? $base->weatherLocale,
        );
    }
}
