<?php

declare(strict_types=1);

namespace App\Tui\Configuration;

enum SaveOutcome: string
{
    case Saved = 'saved';
    case ValidationFailed = 'validation-failed';
    case WriteFailed = 'write-failed';
}
