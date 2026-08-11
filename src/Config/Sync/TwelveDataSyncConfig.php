<?php

declare(strict_types=1);

namespace App\Config\Sync;

final readonly class TwelveDataSyncConfig extends TrackerSyncConfig
{
    public static function syncType(): string
    {
        return 'twelvedata';
    }

    /**
     * None: no API key was available to check what this source serves as history, and a high built
     * from what the client alone has seen is wrong from the first day.
     */
    public static function acceptedBottomLines(): array
    {
        return [];
    }
}
