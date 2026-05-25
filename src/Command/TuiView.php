<?php

declare(strict_types=1);

namespace App\Command;

enum TuiView: string
{
    case Main = 'main';
    case Scenarios = 'scenarios';
    case SyncNow = 'sync-now';
    case ResetSim = 'reset-sim';
    case Configuration = 'configuration';
}
