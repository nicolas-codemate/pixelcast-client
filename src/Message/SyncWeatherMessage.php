<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncWeatherMessage implements SyncMessage
{
    public function dispatchedByHand(): static
    {
        // The weather group has no items to select, and its own window is only ever read by the scheduler.
        return $this;
    }
}
