<?php

declare(strict_types=1);

namespace App\Command;

enum TuiView: string
{
    case Main = 'main';
    case Scenarios = 'scenarios';
}
