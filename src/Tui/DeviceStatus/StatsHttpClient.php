<?php

declare(strict_types=1);

namespace App\Tui\DeviceStatus;

final class StatsHttpClient implements StatsTransport
{
    public function __construct(
        private readonly float $timeoutSeconds = 0.5,
    ) {
    }

    public function fetch(?string $baseUrl): StatsSnapshot
    {
        if (null === $baseUrl || '' === $baseUrl) {
            return StatsSnapshot::unreachable('no base URL configured');
        }

        $statsUrl = rtrim($baseUrl, '/').'/api/stats';

        $streamContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        // file_get_contents emits a PHP warning on connection failure; we
        // swallow it here because we already detect the failure via false.
        set_error_handler(static fn (): bool => true);
        try {
            $response = file_get_contents($statsUrl, false, $streamContext);
        } finally {
            restore_error_handler();
        }

        if (false === $response) {
            return StatsSnapshot::unreachable('connection failed');
        }

        $decoded = json_decode($response, true);
        if (!\is_array($decoded)) {
            return StatsSnapshot::unreachable('invalid response');
        }

        /** @var array<string, mixed> $payload */
        $payload = $decoded;

        return StatsSnapshot::fromStatsPayload($payload);
    }
}
