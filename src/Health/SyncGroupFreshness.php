<?php

declare(strict_types=1);

namespace App\Health;

final readonly class SyncGroupFreshness
{
    /**
     * @param bool $isWatched false as soon as one of the reasons to leave the group alone holds: its
     *                        active window is closed, or the panel is off
     * @param bool $asleep only decides how the group is described: a group asleep and a group
     *                     outside its active window are both simply not watched
     */
    public function __construct(
        public string $syncType,
        public ?int $ageInSeconds,
        public int $staleAfterInSeconds,
        public bool $isWatched,
        public ?int $secondsSinceWatchedAgain,
        public bool $asleep,
    ) {
    }

    public function isStale(): bool
    {
        if (!$this->isWatched) {
            return false;
        }

        return $this->watchedSilenceInSeconds() > $this->staleAfterInSeconds;
    }

    /**
     * How long the group has been watched again, when it has only been watched since then rather
     * than since its last push. Null when the last push is what the group is judged on.
     */
    public function judgedSecondsSinceWatchedAgain(): ?int
    {
        $secondsSinceWatchedAgain = $this->secondsSinceWatchedAgain;

        if (null === $secondsSinceWatchedAgain || $secondsSinceWatchedAgain >= ($this->ageInSeconds ?? \PHP_INT_MAX)) {
            return null;
        }

        return $secondsSinceWatchedAgain;
    }

    private function watchedSilenceInSeconds(): int
    {
        return $this->judgedSecondsSinceWatchedAgain() ?? $this->ageInSeconds ?? \PHP_INT_MAX;
    }
}
