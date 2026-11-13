<?php

declare(strict_types=1);

namespace App\Client;

enum StaleBehavior: string
{
    case Hide = 'hide';
    case Dim = 'dim';
    case Badge = 'badge';
    case None = 'none';

    /**
     * `dim` and `badge` are drawn by the tracker, gauge and watchlist layouts only, so every other
     * endpoint answers HTTP 400 on them.
     */
    public const array ACCEPTED_OUTSIDE_TRACKER_AND_GAUGE = [self::Hide, self::None];
}
