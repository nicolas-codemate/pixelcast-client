<?php

declare(strict_types=1);

namespace App\Config\Sync;

final readonly class CoinGeckoSyncConfig extends TrackerSyncConfig
{
    public static function syncType(): string
    {
        return 'coingecko';
    }
}
