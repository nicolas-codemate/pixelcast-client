<?php

declare(strict_types=1);

namespace App\Tui\Inspector;

final class InspectorHttpClient implements InspectorTransport
{
    public function __construct(
        private readonly float $timeoutSeconds = 0.5,
    ) {
    }

    public function fetch(?string $baseUrl): InspectorSnapshot
    {
        if (null === $baseUrl || '' === $baseUrl) {
            return InspectorSnapshot::unreachable('no base URL configured');
        }

        $inspectorUrl = rtrim($baseUrl, '/').'/__inspect';

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
            $response = file_get_contents($inspectorUrl, false, $streamContext);
        } finally {
            restore_error_handler();
        }

        if (false === $response) {
            return InspectorSnapshot::unreachable('connection failed');
        }

        $decoded = json_decode($response, true);
        if (!\is_array($decoded)) {
            return InspectorSnapshot::unreachable('invalid response');
        }

        /** @var array<string, mixed> $payload */
        $payload = $decoded;

        return InspectorSnapshot::fromInspectPayload($payload);
    }
}
