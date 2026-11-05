<?php

declare(strict_types=1);

namespace App\Tracker\Boursorama;

final readonly class BoursoramaSessionCloses
{
    public function __construct(
        public float $previousSessionClose,
        public float $runningSessionPrice,
    ) {
    }
}
