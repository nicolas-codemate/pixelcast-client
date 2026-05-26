<?php

declare(strict_types=1);

namespace App\Tui\DeviceState\Prod\Transport;

use App\Tui\DeviceState\Prod\Http\HttpJsonFetcher;

final class WeatherTransport
{
    public function __construct(private readonly HttpJsonFetcher $fetcher)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(?string $baseUrl): ?array
    {
        if (null === $baseUrl || '' === $baseUrl) {
            return null;
        }

        return $this->fetcher->fetchJson(rtrim($baseUrl, '/').'/api/weather');
    }
}
