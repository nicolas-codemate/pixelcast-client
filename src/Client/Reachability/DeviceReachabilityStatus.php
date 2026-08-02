<?php

declare(strict_types=1);

namespace App\Client\Reachability;

enum DeviceReachabilityStatus: string
{
    case Reachable = 'reachable';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';
}
