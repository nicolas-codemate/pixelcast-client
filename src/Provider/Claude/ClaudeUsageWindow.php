<?php

declare(strict_types=1);

namespace App\Provider\Claude;

/**
 * One rolling usage window: how much of it is consumed, and when it starts over.
 */
final readonly class ClaudeUsageWindow
{
    /**
     * The reset instant is optional because the endpoint really does answer `null` for it, on the
     * model-scoped weekly window above all. Everything derived from it degrades instead of failing.
     */
    private function __construct(
        public ClaudeUsageWindowKind $kind,
        public int $percent,
        public ?\DateTimeImmutable $resetsAt,
    ) {
    }

    public static function create(ClaudeUsageWindowKind $kind, int $percent, ?\DateTimeImmutable $resetsAt): self
    {
        return new self($kind, $percent, $resetsAt);
    }

    public function secondsInWindow(): int
    {
        return $this->kind->secondsInWindow();
    }
}
