<?php

declare(strict_types=1);

namespace App\Client\Sleep;

final readonly class SleepSlot
{
    public function __construct(
        public string $start,
        public string $end,
    ) {
    }
}
