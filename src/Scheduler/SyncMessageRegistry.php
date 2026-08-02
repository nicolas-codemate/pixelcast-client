<?php

declare(strict_types=1);

namespace App\Scheduler;

interface SyncMessageRegistry
{
    /**
     * Ordered map of sync type identifier to the message that triggers it.
     *
     * @return array<string, object>
     */
    public function syncMessages(): array;
}
