<?php

declare(strict_types=1);

namespace App\Message;

enum SyncOutcome
{
    case Pushed;
    case Skipped;
    case Failed;
}
