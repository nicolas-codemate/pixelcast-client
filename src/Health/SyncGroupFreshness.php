<?php

declare(strict_types=1);

namespace App\Health;

final readonly class SyncGroupFreshness
{
    public function __construct(
        public string $syncType,
        public ?int $ageInSeconds,
        public int $staleAfterInSeconds,
        public bool $insideActiveWindow = true,
        public ?int $secondsSinceWindowOpened = null,
    ) {
    }

    public function isStale(): bool
    {
        if (!$this->insideActiveWindow) {
            return false;
        }

        return $this->watchedSilenceInSeconds() > $this->staleAfterInSeconds;
    }

    /**
     * A group that just reopened has only been watched since its opening, not since its last push.
     */
    private function watchedSilenceInSeconds(): int
    {
        return min($this->ageInSeconds ?? \PHP_INT_MAX, $this->secondsSinceWindowOpened ?? \PHP_INT_MAX);
    }
}
