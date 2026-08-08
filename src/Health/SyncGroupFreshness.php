<?php

declare(strict_types=1);

namespace App\Health;

final readonly class SyncGroupFreshness
{
    public function __construct(
        public string $syncType,
        public ?int $ageInSeconds,
        public int $staleAfterInSeconds,
        public bool $insideActiveWindow,
        public ?int $secondsSinceWindowOpened,
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
     * How long the window has been open, when the group has only been watched since that opening
     * rather than since its last push. Null when the last push is what the group is judged on.
     */
    public function watchedSecondsSinceWindowOpening(): ?int
    {
        $secondsSinceWindowOpened = $this->secondsSinceWindowOpened;

        if (null === $secondsSinceWindowOpened || $secondsSinceWindowOpened >= ($this->ageInSeconds ?? \PHP_INT_MAX)) {
            return null;
        }

        return $secondsSinceWindowOpened;
    }

    private function watchedSilenceInSeconds(): int
    {
        return $this->watchedSecondsSinceWindowOpening() ?? $this->ageInSeconds ?? \PHP_INT_MAX;
    }
}
