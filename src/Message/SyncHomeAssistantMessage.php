<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncHomeAssistantMessage implements SyncMessage
{
    public function dispatchedByHand(): static
    {
        // The sensors are pushed as one app, never one by one, and the group window is only ever read by the scheduler.
        return $this;
    }
}
