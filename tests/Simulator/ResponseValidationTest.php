<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class ResponseValidationTest extends SimulatorWebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function specifiedReadEndpointProvider(): iterable
    {
        yield 'tracker list' => ['/api/trackers'];
        yield 'gauge list' => ['/api/gauges'];
        yield 'custom app list' => ['/api/apps'];
        yield 'notification list' => ['/api/notify/list'];
        yield 'icon list' => ['/api/icons'];
        yield 'sleep' => ['/api/sleep'];
        yield 'stats' => ['/api/stats'];
        yield 'settings' => ['/api/settings'];
    }

    #[DataProvider('specifiedReadEndpointProvider')]
    public function testReadEndpointMatchesItsResponseSchemaAfterAPush(string $path): void
    {
        $this->pushOnePayloadOfEachKind();

        $this->client->request('GET', $path);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testGetWeatherOnTheEmptyStateMatchesItsResponseSchema(): void
    {
        $this->client->request('GET', '/api/weather');

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testResponseTheSpecForbidsIsAnsweredWithAnError(): void
    {
        // POST /api/custom accepts an RGB array as color while AppResponse only allows a hex
        // string, so echoing that colour back is a mismatch a legitimate request can produce.
        $this->postJson('/api/custom?name=rgb', ['text' => 'hi', 'color' => [255, 136, 0]]);
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/apps');

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('error', $this->jsonResponse());
    }

    private function pushOnePayloadOfEachKind(): void
    {
        $this->postJson('/api/tracker?name=BTC', [
            'symbol' => 'BTC',
            'currency' => 'USD',
            'value' => 98452.30,
            'change' => 2.14,
        ]);

        $this->postJson('/api/gauge?name=disks', [
            'title' => 'Disks',
            'rows' => [['label' => 'root', 'percent' => 42]],
        ]);

        $this->postJson('/api/custom?name=foo', [
            'text' => 'hello',
            'icon' => 'smiley',
            'color' => '#FF8800',
            'duration' => 10_000,
        ]);

        $this->postJson('/api/notify', [
            'text' => 'New message!',
            'icon' => 'mail',
            'color' => '#0096FF',
            'duration' => 5_000,
        ]);
    }
}
