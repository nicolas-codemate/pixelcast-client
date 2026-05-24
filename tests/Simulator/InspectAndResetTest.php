<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class InspectAndResetTest extends SimulatorWebTestCase
{
    public function testInspectStructure(): void
    {
        $this->client->request('GET', '/__inspect');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('state', $payload);
        self::assertArrayHasKey('requests', $payload);
        self::assertIsArray($payload['requests']);
    }

    public function testResetClearsStateAndRequests(): void
    {
        $this->postJson('/weather', [
            'current' => [
                'icon' => 'w_clear_day',
                'temp' => 22,
            ],
            'forecast' => [],
        ]);
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->postJson('/brightness', ['brightness' => 180]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/__reset');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $resetPayload = $this->jsonResponse();
        self::assertTrue($resetPayload['success'] ?? null);

        $this->client->request('GET', '/__inspect');
        $inspectPayload = $this->jsonResponse();

        $state = $inspectPayload['state'] ?? null;
        self::assertIsArray($state);

        $weatherState = $state['weather'] ?? null;
        self::assertIsArray($weatherState);
        self::assertFalse($weatherState['valid'] ?? null);

        $requests = $inspectPayload['requests'] ?? null;
        self::assertIsArray($requests);
        self::assertEmpty($requests);
    }
}
