<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * Where a watchlist row reads its quote. The value is the one written under `source` in pixelcast.yaml.
 */
enum WatchlistQuoteSource: string
{
    case Boursorama = 'boursorama';
}
