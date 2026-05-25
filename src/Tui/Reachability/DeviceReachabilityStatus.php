<?php

declare(strict_types=1);

namespace App\Tui\Reachability;

enum DeviceReachabilityStatus: string
{
    case Reachable = 'reachable';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';
}
