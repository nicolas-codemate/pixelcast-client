<?php

declare(strict_types=1);

namespace App\Client\Stats;

final readonly class WifiStatus
{
    public function __construct(
        public ?string $ssid,
        public ?int $signalStrengthDbm,
        public ?string $ipAddress,
    ) {
    }
}
