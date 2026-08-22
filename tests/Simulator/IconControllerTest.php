<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class IconControllerTest extends SimulatorWebTestCase
{
    public function testListInitiallyEmpty(): void
    {
        $this->client->request('GET', '/api/icons');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame(0, $payload['count'] ?? null);

        $icons = $payload['icons'] ?? null;
        self::assertIsArray($icons);
        self::assertEmpty($icons);
    }

    public function testAddAndList(): void
    {
        $this->uploadIcon('smiley');
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/icons');
        $payload = $this->jsonResponse();
        self::assertSame(1, $payload['count'] ?? null);
    }

    public function testGetIconReturnsPng(): void
    {
        $this->uploadIcon('smiley');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/icons/smiley');
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $contentType = (string) $response->headers->get('Content-Type');
        self::assertStringContainsString('image/png', $contentType);
    }

    public function testGetUnknownIconReturns404(): void
    {
        $this->client->request('GET', '/api/icons/notthere');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/api/icons?name=notthere');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }
}
