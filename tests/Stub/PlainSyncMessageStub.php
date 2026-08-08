<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Message\SyncMessage;

final class PlainSyncMessageStub implements SyncMessage
{
    public function dispatchedByHand(): static
    {
        return $this;
    }
}
