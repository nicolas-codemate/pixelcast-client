<?php

declare(strict_types=1);

namespace App\Client\Transport;

use App\Client\Http\HttpJsonFetcher;

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

        return $this->fetcher->fetchJson(rtrim($baseUrl, '/').'/weather');
    }
}
