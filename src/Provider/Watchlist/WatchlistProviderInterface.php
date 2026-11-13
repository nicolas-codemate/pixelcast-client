<?php

declare(strict_types=1);

namespace App\Provider\Watchlist;

use App\Client\Watchlist\WatchlistPayload;
use App\Config\Exception\PixelCastConfigException;

interface WatchlistProviderInterface
{
    /**
     * @return WatchlistPayload|null null when a quote could not be read, so that the device keeps the
     *                               complete list it already shows instead of one missing a row
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchWatchlist(): ?WatchlistPayload;
}
