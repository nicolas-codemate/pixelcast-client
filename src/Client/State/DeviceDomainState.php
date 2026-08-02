<?php

declare(strict_types=1);

namespace App\Client\State;

final readonly class DeviceDomainState
{
    public function __construct(
        public bool $hasData,
        public mixed $payload,
    ) {
    }
}
