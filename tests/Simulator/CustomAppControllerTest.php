<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class CustomAppControllerTest extends SimulatorWebTestCase
{
    public function testPostThenListIncludesApp(): void
    {
        $this->postJson('/custom?name=foo', [
            'name' => 'foo',
            'text' => 'hello',
            'icon' => 'smiley',
            'color' => '#FF8800',
            'duration' => 10_000,
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/apps');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        $apps = $payload['apps'] ?? null;
        self::assertIsArray($apps);
        self::assertNotEmpty($apps);

        $ids = array_map(
            static fn (mixed $entry): string => \is_array($entry) && \is_string($entry['id'] ?? null) ? $entry['id'] : '',
            $apps,
        );
        self::assertContains('foo', $ids);
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/custom?name=foo');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('not found', $payload['error'] ?? null);
    }
}
