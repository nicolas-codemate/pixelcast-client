<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncTrackerMessage
{
    public function __construct(
        public string $syncType,
    ) {
    }
}
