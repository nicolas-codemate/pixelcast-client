<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * Whether a sync group has anything to push at a given instant, and how long that has been true.
 * A group active with no window in play has been active since forever, which no number expresses,
 * so those seconds are null.
 */
final readonly class SyncGroupActivity
{
    private function __construct(
        public bool $isActive,
        public ?int $secondsSinceBecameActive,
    ) {
    }

    public static function inactive(): self
    {
        return new self(false, null);
    }

    public static function activeSince(?int $secondsSinceBecameActive): self
    {
        return new self(true, $secondsSinceBecameActive);
    }
}
