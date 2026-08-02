<?php

declare(strict_types=1);

namespace App\Config;

enum WeatherLocale: string
{
    case French = 'fr';
    case English = 'en';

    private const array FRENCH_DAY_LABELS = [
        1 => 'LUN',
        2 => 'MAR',
        3 => 'MER',
        4 => 'JEU',
        5 => 'VEN',
        6 => 'SAM',
        7 => 'DIM',
    ];

    private const array ENGLISH_DAY_LABELS = [
        1 => 'MON',
        2 => 'TUE',
        3 => 'WED',
        4 => 'THU',
        5 => 'FRI',
        6 => 'SAT',
        7 => 'SUN',
    ];

    public function dayLabelFor(\DateTimeImmutable $date): string
    {
        $dayLabels = match ($this) {
            self::French => self::FRENCH_DAY_LABELS,
            self::English => self::ENGLISH_DAY_LABELS,
        };

        $isoDayOfWeek = (int) $date->format('N');

        return $dayLabels[$isoDayOfWeek];
    }
}
