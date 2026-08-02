<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class NotificationControllerTest extends SimulatorWebTestCase
{
    public function testEnqueueAndList(): void
    {
        $this->postJson('/api/notify', [
            'text' => 'New message!',
            'icon' => 'mail',
            'color' => '#0096FF',
            'duration' => 5_000,
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $payload = $this->jsonResponse();
        self::assertTrue($payload['success'] ?? null);
        self::assertArrayHasKey('id', $payload);
        self::assertIsString($payload['id']);

        $this->client->request('GET', '/api/notify/list');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $listPayload = $this->jsonResponse();
        self::assertGreaterThanOrEqual(1, $listPayload['count'] ?? 0);
    }

    public function testDismissEmptyReturns404(): void
    {
        $this->client->request('POST', '/api/notify/dismiss');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('error', $payload);
    }

    public function testDismissNonEmpty(): void
    {
        $this->postJson('/api/notify', ['text' => 'hello']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/notify/dismiss');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertTrue($payload['success'] ?? null);
    }
}
