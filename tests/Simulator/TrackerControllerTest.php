<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class TrackerControllerTest extends SimulatorWebTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validTrackerPayload(): array
    {
        return [
            'symbol' => 'BTC',
            'currency' => 'USD',
            'value' => 98452.30,
            'change' => 2.14,
        ];
    }

    public function testPostThenListIncludesTracker(): void
    {
        $this->postJson('/api/tracker?name=BTC', self::validTrackerPayload());
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/trackers');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        $trackers = $payload['trackers'] ?? null;
        self::assertIsArray($trackers);
        self::assertNotEmpty($trackers);

        $first = $trackers[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('BTC', $first['symbol'] ?? null);
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/api/tracker?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('not found', $payload['error'] ?? null);
    }

    public function testGetUnknownReturns404(): void
    {
        $this->client->request('GET', '/api/tracker?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testGetExistingReturnsPayload(): void
    {
        $this->postJson('/api/tracker?name=BTC', self::validTrackerPayload());

        $this->client->request('GET', '/api/tracker?name=BTC');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('BTC', $payload['name'] ?? null);
        self::assertSame('BTC', $payload['symbol'] ?? null);
    }
}
