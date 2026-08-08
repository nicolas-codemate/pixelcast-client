<?php

declare(strict_types=1);

namespace App\Config\Sync;

enum ActiveWindowDay: string
{
    case Monday = 'mon';
    case Tuesday = 'tue';
    case Wednesday = 'wed';
    case Thursday = 'thu';
    case Friday = 'fri';
    case Saturday = 'sat';
    case Sunday = 'sun';

    public function isoWeekdayNumber(): int
    {
        return match ($this) {
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
            self::Sunday => 7,
        };
    }

    public static function fromIsoWeekdayNumber(int $isoWeekdayNumber): self
    {
        return match ($isoWeekdayNumber) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            7 => self::Sunday,
            default => throw new \InvalidArgumentException(\sprintf('Expected an ISO weekday number between 1 and 7, got %d.', $isoWeekdayNumber)),
        };
    }
}
