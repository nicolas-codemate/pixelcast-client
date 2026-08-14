<?php

declare(strict_types=1);

namespace App\Client\Stats;

/**
 * What GET /stats reports about the device: its firmware, its memory and its network link.
 */
final readonly class StatsSnapshot
{
    private function __construct(
        public ?string $firmwareVersion,
        public ?int $uptimeSeconds,
        public ?int $freeHeapBytes,
        public ?int $maxAllocatableHeapBytes,
        public ?int $brightness,
        public ?WifiStatus $wifi,
    ) {
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    public static function fromResponseBody(array $decodedBody): self
    {
        $firmwareVersion = $decodedBody['version'] ?? null;
        $uptimeSeconds = $decodedBody['uptime'] ?? null;
        $freeHeapBytes = $decodedBody['freeHeap'] ?? null;
        $maxAllocatableHeapBytes = $decodedBody['maxAllocHeap'] ?? null;
        $brightness = $decodedBody['brightness'] ?? null;

        return new self(
            firmwareVersion: \is_string($firmwareVersion) ? $firmwareVersion : null,
            uptimeSeconds: \is_int($uptimeSeconds) ? $uptimeSeconds : null,
            freeHeapBytes: \is_int($freeHeapBytes) ? $freeHeapBytes : null,
            maxAllocatableHeapBytes: \is_int($maxAllocatableHeapBytes) ? $maxAllocatableHeapBytes : null,
            brightness: \is_int($brightness) ? $brightness : null,
            wifi: self::readWifiStatus($decodedBody['wifi'] ?? null),
        );
    }

    private static function readWifiStatus(mixed $wifi): ?WifiStatus
    {
        if (!\is_array($wifi)) {
            return null;
        }

        $ssid = $wifi['ssid'] ?? null;
        $signalStrengthDbm = $wifi['rssi'] ?? null;
        $ipAddress = $wifi['ip'] ?? null;

        return new WifiStatus(
            \is_string($ssid) ? $ssid : null,
            \is_int($signalStrengthDbm) ? $signalStrengthDbm : null,
            \is_string($ipAddress) ? $ipAddress : null,
        );
    }
}
