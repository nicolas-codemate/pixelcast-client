<?php

declare(strict_types=1);

namespace App\Tui\DeviceStatus;

final readonly class StatsSnapshot
{
    public function __construct(
        public bool $reachable,
        public ?string $errorMessage = null,
        public ?int $uptimeSeconds = null,
        public ?int $freeHeapBytes = null,
        public ?string $firmwareVersion = null,
        public ?string $ipAddress = null,
        public ?int $brightness = null,
        public ?bool $autoRotate = null,
        public ?int $defaultDurationSeconds = null,
    ) {
    }

    public static function unreachable(string $errorMessage): self
    {
        return new self(reachable: false, errorMessage: $errorMessage);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromStatsPayload(array $payload): self
    {
        $wifi = self::nestedArray($payload, 'wifi');
        $apps = self::nestedArray($payload, 'apps');

        return new self(
            reachable: true,
            uptimeSeconds: self::intOrNull($payload, 'uptime'),
            freeHeapBytes: self::intOrNull($payload, 'freeHeap'),
            firmwareVersion: self::stringOrNull($payload, 'version'),
            ipAddress: self::stringOrNull($wifi, 'ip'),
            brightness: self::intOrNull($payload, 'brightness'),
            autoRotate: self::boolOrNull($apps, 'rotationEnabled'),
            defaultDurationSeconds: self::intOrNull($payload, 'defaultDuration'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function nestedArray(array $payload, string $key): array
    {
        if (!isset($payload[$key]) || !\is_array($payload[$key])) {
            return [];
        }

        /** @var array<string, mixed> $nested */
        $nested = $payload[$key];

        return $nested;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function intOrNull(array $payload, string $key): ?int
    {
        return isset($payload[$key]) && \is_int($payload[$key]) ? $payload[$key] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function stringOrNull(array $payload, string $key): ?string
    {
        return isset($payload[$key]) && \is_string($payload[$key]) ? $payload[$key] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function boolOrNull(array $payload, string $key): ?bool
    {
        return isset($payload[$key]) && \is_bool($payload[$key]) ? $payload[$key] : null;
    }
}
