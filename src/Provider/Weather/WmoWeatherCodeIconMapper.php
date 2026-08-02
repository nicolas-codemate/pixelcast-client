<?php

declare(strict_types=1);

namespace App\Provider\Weather;

use App\Client\Weather\WeatherIcon;

final class WmoWeatherCodeIconMapper
{
    private const array WEATHER_CODE_TO_ICON = [
        3 => WeatherIcon::Cloudy,
        45 => WeatherIcon::Fog,
        48 => WeatherIcon::Fog,
        51 => WeatherIcon::Rain,
        53 => WeatherIcon::Rain,
        55 => WeatherIcon::Rain,
        56 => WeatherIcon::Rain,
        57 => WeatherIcon::Rain,
        61 => WeatherIcon::Rain,
        63 => WeatherIcon::Rain,
        65 => WeatherIcon::HeavyRain,
        66 => WeatherIcon::Rain,
        67 => WeatherIcon::HeavyRain,
        71 => WeatherIcon::Snow,
        73 => WeatherIcon::Snow,
        75 => WeatherIcon::Snow,
        77 => WeatherIcon::Snow,
        80 => WeatherIcon::Rain,
        81 => WeatherIcon::Rain,
        82 => WeatherIcon::HeavyRain,
        85 => WeatherIcon::Snow,
        86 => WeatherIcon::Snow,
        95 => WeatherIcon::Thunder,
        96 => WeatherIcon::Thunder,
        99 => WeatherIcon::Thunder,
    ];

    // A code outside the documented WMO table returns null so the caller can log it and choose its own fallback.
    public static function mapToIcon(int $weatherCode, bool $isDay): ?WeatherIcon
    {
        return match ($weatherCode) {
            0, 1 => $isDay ? WeatherIcon::ClearDay : WeatherIcon::ClearNight,
            2 => $isDay ? WeatherIcon::PartlyDay : WeatherIcon::PartlyNight,
            default => self::WEATHER_CODE_TO_ICON[$weatherCode] ?? null,
        };
    }
}
