<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class WeatherControllerTest extends SimulatorWebTestCase
{
    public function testGetWeatherInitiallyReturnsValidFalse(): void
    {
        $this->client->request('GET', '/weather');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertFalse($payload['valid'] ?? null);
    }

    public function testPostValidWeatherIsReflectedInGet(): void
    {
        $this->postJson('/weather', [
            'current' => [
                'icon' => 'w_clear_day',
                'temp' => 22,
                'humidity' => 50,
            ],
            'forecast' => [],
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/weather');
        $payload = $this->jsonResponse();

        self::assertTrue($payload['valid'] ?? null);

        $current = $payload['current'] ?? null;
        self::assertIsArray($current);
        self::assertSame('w_clear_day', $current['icon'] ?? null);
    }
}
