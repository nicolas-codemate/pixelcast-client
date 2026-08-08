<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncTrackerMessage implements SyncMessage
{
    public function __construct(
        public string $syncType,
        public bool $honoursActiveWindows = true,
    ) {
    }

    public function dispatchedByHand(): static
    {
        return new self($this->syncType, honoursActiveWindows: false);
    }
}
