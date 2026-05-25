<?php

declare(strict_types=1);

namespace App\Tui\Reachability;

final readonly class DeviceReachabilityResult
{
    public function __construct(
        public DeviceReachabilityStatus $status,
        public string $displayLabel,
    ) {
    }

    public static function fromStatus(DeviceReachabilityStatus $status): self
    {
        $defaultLabel = match ($status) {
            DeviceReachabilityStatus::Reachable => 'Reachable',
            DeviceReachabilityStatus::Unreachable => 'Unreachable',
            DeviceReachabilityStatus::Unknown => 'Unknown',
        };

        return new self($status, $defaultLabel);
    }
}
