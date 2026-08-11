<?php

declare(strict_types=1);

namespace App\Config\Sync;

final readonly class BoursoramaSyncConfig extends TrackerSyncConfig
{
    public static function syncType(): string
    {
        return 'boursorama';
    }

    public static function acceptedBottomLines(): array
    {
        return [BottomLine::AllTimeHigh];
    }
}
