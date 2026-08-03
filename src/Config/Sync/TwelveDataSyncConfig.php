<?php

declare(strict_types=1);

namespace App\Config\Sync;

final readonly class TwelveDataSyncConfig extends TrackerSyncConfig
{
    public static function syncType(): string
    {
        return 'twelvedata';
    }
}
