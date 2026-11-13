<?php

declare(strict_types=1);

namespace App\Provider\Watchlist;

final readonly class WatchlistQuote
{
    public function __construct(
        public float $price,
        public float $changePercentage,
    ) {
    }
}
