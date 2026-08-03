<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\WeatherLocale;
use App\Config\WeatherUnits;
use App\Message\SyncWeatherMessage;

final readonly class WeatherSyncConfig implements SyncGroupConfig
{
    public function __construct(
        public bool $enabled,
        public SyncInterval $interval,
        public float $latitude,
        public float $longitude,
        public WeatherUnits $units,
        public WeatherLocale $locale,
    ) {
    }

    public static function syncType(): string
    {
        return 'weather';
    }

    public static function fromOptions(array $options): self
    {
        $optionsPath = 'syncs.'.self::syncType();

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: SyncInterval::fromOptions($options, $optionsPath),
            latitude: SyncOptionReader::requireFloat($options, 'latitude', $optionsPath),
            longitude: SyncOptionReader::requireFloat($options, 'longitude', $optionsPath),
            units: SyncOptionReader::requireEnum($options, 'units', $optionsPath, WeatherUnits::class),
            locale: SyncOptionReader::requireEnum($options, 'locale', $optionsPath, WeatherLocale::class),
        );
    }

    public function syncMessage(): object
    {
        return new SyncWeatherMessage();
    }
}
