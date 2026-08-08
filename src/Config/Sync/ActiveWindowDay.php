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
}
