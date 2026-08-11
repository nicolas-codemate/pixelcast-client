<?php

declare(strict_types=1);

namespace App\Tracker;

/**
 * The highest price known for a tracked asset, identified by the sync type that follows it, its
 * symbol and its currency.
 *
 * `reachedAt` is nullable because a source can serve a high without saying when it happened, and it
 * is always the caller that fills it: the sync instant for a live price, the day of the winning bar
 * for a high read in a series of bars.
 */
final readonly class AllTimeHigh
{
    public function __construct(
        public string $syncType,
        public string $symbol,
        public string $currency,
        public float $price,
        public ?\DateTimeImmutable $reachedAt,
        public \DateTimeImmutable $observedAt,
    ) {
    }
}
