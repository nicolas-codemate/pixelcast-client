<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Message\SyncMessage;
use App\Scheduler\SyncMessageRegistry;

final class StaticSyncMessageRegistryStub implements SyncMessageRegistry
{
    /**
     * @param array<string, SyncMessage> $syncMessages
     */
    public function __construct(
        private readonly array $syncMessages = [],
    ) {
    }

    public function syncMessages(): array
    {
        return $this->syncMessages;
    }
}
