<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class GaugeControllerTest extends SimulatorWebTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validGaugePayload(): array
    {
        return [
            'title' => 'Disks',
            'rows' => [
                ['label' => 'root', 'percent' => 42],
                ['label' => 'data', 'percent' => 71],
            ],
        ];
    }

    public function testPostThenListIncludesGauge(): void
    {
        $this->postJson('/api/gauge?name=disks', self::validGaugePayload());
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $first = $this->firstItemOfList('/api/gauges', 'gauges');

        self::assertSame(1, $this->jsonResponse()['count'] ?? null);
        self::assertSame('disks', $first['name'] ?? null);
        self::assertSame('Disks', $first['title'] ?? null);
        self::assertSame(2, $first['rowCount'] ?? null);
    }

    public function testAColoredTitleIsListedAsPlainTextAndStoredAsSent(): void
    {
        $coloredTitle = [
            ['t' => 'Claude ', 'c' => '#FFFFFF'],
            ['t' => 'Max', 'c' => '#D97757'],
        ];
        $this->postJson('/api/gauge?name=claude', [
            'title' => $coloredTitle,
            'rows' => [['label' => '5h', 'percent' => 41]],
        ]);

        self::assertSame('Claude Max', $this->firstItemOfList('/api/gauges', 'gauges')['title'] ?? null);

        $this->client->request('GET', '/api/gauge?name=claude');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame($coloredTitle, $this->jsonResponse()['title'] ?? null);
    }

    public function testGetExistingReturnsPayload(): void
    {
        $this->postJson('/api/gauge?name=disks', self::validGaugePayload());

        $this->client->request('GET', '/api/gauge?name=disks');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('disks', $payload['name'] ?? null);
        self::assertSame('Disks', $payload['title'] ?? null);
    }

    public function testGetUnknownReturns404(): void
    {
        $this->client->request('GET', '/api/gauge?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesTheGauge(): void
    {
        $this->postJson('/api/gauge?name=disks', self::validGaugePayload());

        $this->deleteRequest('/api/gauge?name=disks');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/gauges');
        self::assertSame(0, $this->jsonResponse()['count'] ?? null);
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/api/gauge?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('not found', $payload['error'] ?? null);
    }
}
