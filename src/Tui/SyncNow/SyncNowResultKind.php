<?php

declare(strict_types=1);

namespace App\Tui\SyncNow;

enum SyncNowResultKind: string
{
    case Dispatched = 'dispatched';
    case NotWired = 'not_wired';
    case DispatchError = 'dispatch_error';
}
