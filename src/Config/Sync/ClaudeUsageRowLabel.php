<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * The four rows the claude gauge can produce, keyed on the label the device shows: the
 * operator has no other name for a row, so hiddenRows is declared in these same strings.
 */
enum ClaudeUsageRowLabel: string
{
    case Session = '5h';
    case WeeklyAll = '7j';
    case Fable = 'fable';
    case Credits = 'credits';
}
