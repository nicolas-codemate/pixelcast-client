<?php

declare(strict_types=1);

namespace App\Provider\Claude;

/**
 * One reading of the usage endpoint, with every field independent of the others: a window the
 * response no longer carries costs its own row, never the whole gauge.
 */
final readonly class ClaudeUsageSnapshot
{
    private function __construct(
        public ?ClaudeUsageWindow $session,
        public ?ClaudeUsageWindow $weeklyAll,
        public ?ClaudeUsageWindow $fableWeekly,
        public ?ClaudeSpend $spend,
    ) {
    }

    public static function create(
        ?ClaudeUsageWindow $session,
        ?ClaudeUsageWindow $weeklyAll,
        ?ClaudeUsageWindow $fableWeekly,
        ?ClaudeSpend $spend,
    ): self {
        return new self($session, $weeklyAll, $fableWeekly, $spend);
    }

    public function isEmpty(): bool
    {
        return null === $this->session
            && null === $this->weeklyAll
            && null === $this->fableWeekly
            && null === $this->spend;
    }
}
