<?php

declare(strict_types=1);

namespace App\Client\State;

enum DeviceTargetKind: string
{
    case Simulator = 'simulator';
    case Firmware = 'firmware';
}
