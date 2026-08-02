<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\SyncMessageRegistry;

final class StaticSyncMessageRegistryStub implements SyncMessageRegistry
{
    /**
     * @param array<string, object> $syncMessages
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
