<?php

declare(strict_types=1);

namespace App\Config;

enum WeatherUnits: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';
}
