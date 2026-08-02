<?php

declare(strict_types=1);

namespace App\Provider\Weather;

enum WeatherUnits: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function temperatureUnitParameter(): string
    {
        return match ($this) {
            self::Metric => 'celsius',
            self::Imperial => 'fahrenheit',
        };
    }
}
