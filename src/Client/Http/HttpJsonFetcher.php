<?php

declare(strict_types=1);

namespace App\Client\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpJsonFetcher
{
    public function __construct(
        #[Target('device.client')]
        private readonly HttpClientInterface $deviceClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the endpoint is unreachable or the response is not a JSON object
     */
    public function fetchJson(string $url): ?array
    {
        try {
            $response = $this->deviceClient->request('GET', $url);
            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Device request failed', [
                'url' => $url,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->warning('Device request returned an unexpected status', [
                'url' => $url,
                'status' => $statusCode,
            ]);

            return null;
        }

        $decoded = json_decode($rawBody, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
