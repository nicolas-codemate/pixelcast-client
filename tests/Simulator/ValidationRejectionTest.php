<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class ValidationRejectionTest extends SimulatorWebTestCase
{
    public function testInvalidWeatherPayloadIs400(): void
    {
        $this->postJson('/weather', ['invalid_field' => true]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('error', $payload);
    }

    public function testStateUnchangedAfterRejection(): void
    {
        $this->client->request('GET', '/weather');
        $before = $this->jsonResponse();
        self::assertFalse($before['valid'] ?? null);

        $this->postJson('/weather', ['invalid_field' => true]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/weather');
        $after = $this->jsonResponse();
        self::assertFalse($after['valid'] ?? null);
    }

    public function testInvalidTrackerPayloadIs400(): void
    {
        $this->postJson('/tracker?name=BTC', ['value' => 'should-be-number']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('error', $payload);
    }
}
