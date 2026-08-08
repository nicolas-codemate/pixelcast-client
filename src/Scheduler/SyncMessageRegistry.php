<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\SyncMessage;

interface SyncMessageRegistry
{
    /**
     * Ordered map of sync type identifier to the message that triggers it.
     *
     * @return array<string, SyncMessage>
     */
    public function syncMessages(): array;
}
