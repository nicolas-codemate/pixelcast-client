<?php

declare(strict_types=1);

namespace App\Health;

final readonly class SyncGroupFreshness
{
    public function __construct(
        public string $syncType,
        public ?int $ageInSeconds,
        public int $staleAfterInSeconds,
    ) {
    }

    public function isStale(): bool
    {
        return null === $this->ageInSeconds || $this->ageInSeconds > $this->staleAfterInSeconds;
    }
}
