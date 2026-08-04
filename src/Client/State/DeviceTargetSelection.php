<?php

declare(strict_types=1);

namespace App\Client\State;

final readonly class DeviceTargetSelection
{
    private function __construct(
        public DeviceTargetKind $targetKind,
        public DeviceStateSource $stateSource,
        public ?string $inspectorProbeError,
    ) {
    }

    public static function simulator(DeviceStateSource $stateSource): self
    {
        return new self(DeviceTargetKind::Simulator, $stateSource, null);
    }

    public static function firmware(DeviceStateSource $stateSource, string $inspectorProbeError): self
    {
        return new self(DeviceTargetKind::Firmware, $stateSource, $inspectorProbeError);
    }
}
