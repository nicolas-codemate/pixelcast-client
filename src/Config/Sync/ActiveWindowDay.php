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

    /**
     * The single place tying the three-letter day PHP formats to the values written in the file.
     */
    public static function ofLocalInstant(\DateTimeImmutable $localInstant): self
    {
        return self::from(strtolower($localInstant->format('D')));
    }
}
