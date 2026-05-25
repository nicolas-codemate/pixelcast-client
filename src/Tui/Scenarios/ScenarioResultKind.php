<?php

declare(strict_types=1);

namespace App\Tui\Scenarios;

enum ScenarioResultKind: string
{
    case Success = 'success';
    case ValidationFailure = 'validation_failure';
    case TransportFailure = 'transport_failure';
    case Unreachable = 'unreachable';
}
