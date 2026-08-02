<?php

declare(strict_types=1);

namespace App\Client\Weather;

// The firmware also accepts icons uploaded to its filesystem; this list only covers the 10 built-in ones.
enum WeatherIcon: string
{
    case ClearDay = 'w_clear_day';
    case ClearNight = 'w_clear_night';
    case PartlyDay = 'w_partly_day';
    case PartlyNight = 'w_partly_night';
    case Cloudy = 'w_cloudy';
    case Rain = 'w_rain';
    case HeavyRain = 'w_heavy_rain';
    case Thunder = 'w_thunder';
    case Snow = 'w_snow';
    case Fog = 'w_fog';
}
