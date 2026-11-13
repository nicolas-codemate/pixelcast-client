<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\Watchlist\WatchlistPayload;
use App\Provider\Watchlist\WatchlistProviderInterface;

final class StaticWatchlistProviderStub implements WatchlistProviderInterface
{
    public function __construct(
        private readonly ?WatchlistPayload $watchlist = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function fetchWatchlist(): ?WatchlistPayload
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->watchlist;
    }
}
