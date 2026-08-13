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

    /**
     * The neutral element of combinedWith(): a constraint that is not declared at all never closes
     * the group and never moves the instant it became active.
     */
    public static function alwaysActive(): self
    {
        return self::activeSince(null);
    }

    /**
     * Two constraints in play at once: the group has something to push only while both hold, and it
     * has had it only since the later of the two became true.
     */
    public function combinedWith(self $other): self
    {
        if (!$this->isActive || !$other->isActive) {
            return self::inactive();
        }

        return self::activeSince(self::secondsSinceTheLaterBecoming(
            $this->secondsSinceBecameActive,
            $other->secondsSinceBecameActive,
        ));
    }

    /**
     * The later of the two beginnings is the smaller count of seconds. Null stands for a beginning
     * infinitely far back, which any count is later than.
     */
    private static function secondsSinceTheLaterBecoming(?int $firstBecoming, ?int $secondBecoming): ?int
    {
        if (null === $firstBecoming) {
            return $secondBecoming;
        }

        if (null === $secondBecoming) {
            return $firstBecoming;
        }

        return min($firstBecoming, $secondBecoming);
    }
}
