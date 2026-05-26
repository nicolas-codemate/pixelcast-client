<?php

declare(strict_types=1);

namespace App\Tui\DeviceState\Prod\Http;

class HttpJsonFetcher
{
    public function __construct(
        private readonly float $timeoutSeconds = 0.5,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the endpoint is unreachable or the response is not a JSON object
     */
    public function fetchJson(string $url): ?array
    {
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
            $response = file_get_contents($url, false, $streamContext);
        } finally {
            restore_error_handler();
        }

        if (false === $response) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
