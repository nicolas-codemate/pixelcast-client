<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Simulator\Controller\WeatherController;
use Symfony\Component\HttpFoundation\Response;

final class WeatherControllerTest extends SimulatorWebTestCase
{
    public function testGetWeatherInitiallyReturnsValidFalse(): void
    {
        $this->client->request('GET', '/api/weather');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertFalse($payload['valid'] ?? null);
    }

    public function testGetWeatherAnnouncesTheContractFreshnessDefaults(): void
    {
        $this->client->request('GET', '/api/weather');

        $payload = $this->jsonResponse();

        self::assertSame(WeatherController::DEFAULT_STALE_AFTER_SECONDS, $payload['staleAfter'] ?? null);
        self::assertSame(WeatherController::DEFAULT_STALE_BEHAVIOR, $payload['staleBehavior'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
    }

    public function testThePushedFreshnessPolicyIsReadBackFromGet(): void
    {
        $this->postJson('/api/weather', [
            ...self::weatherPushPayload(),
            'staleAfter' => 7200,
            'staleBehavior' => 'none',
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/weather');
        $payload = $this->jsonResponse();

        self::assertSame(7200, $payload['staleAfter'] ?? null);
        self::assertSame('none', $payload['staleBehavior'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
    }

    public function testAStaleAfterOfZeroIsReadBackAsNeverGoingStale(): void
    {
        $this->postJson('/api/weather', [...self::weatherPushPayload(), 'staleAfter' => 0]);

        $this->client->request('GET', '/api/weather');
        $payload = $this->jsonResponse();

        self::assertSame(0, $payload['staleAfter'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
    }

    /**
     * `dim` and `badge` belong to the tracker layout: WeatherUpdateRequest narrows staleBehavior
     * to hide and none, and the spec validator is what turns the others away.
     */
    public function testAStaleBehaviorForeignToTheWeatherAppIsRejected(): void
    {
        $this->postJson('/api/weather', [...self::weatherPushPayload(), 'staleBehavior' => 'dim']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testPostValidWeatherIsReflectedInGet(): void
    {
        $this->postJson('/api/weather', [
            'current' => [
                'icon' => 'w_clear_day',
                'temp' => 22,
                'humidity' => 50,
            ],
            'forecast' => [],
            'today' => [
                'hours' => [
                    ['h' => 15, 'temp' => 22, 'pop' => 0, 'precip' => 0],
                    ['h' => 16, 'temp' => 23, 'pop' => 10, 'precip' => 2],
                ],
            ],
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/weather');
        $payload = $this->jsonResponse();

        self::assertTrue($payload['valid'] ?? null);

        $current = $payload['current'] ?? null;
        self::assertIsArray($current);
        self::assertSame('w_clear_day', $current['icon'] ?? null);

        self::assertSame(
            [
                'hours' => [
                    ['h' => 15, 'temp' => 22, 'pop' => 0, 'precip' => 0],
                    ['h' => 16, 'temp' => 23, 'pop' => 10, 'precip' => 2],
                ],
            ],
            $payload['today'] ?? null,
        );
    }

    public function testAKeyTheForecastDaySchemaDoesNotDeclareIsNotReadBack(): void
    {
        $this->postJson('/api/weather', [
            ...self::weatherPushPayload(),
            'forecast' => [[
                'day' => 'LUN',
                'icon' => 'w_rain',
                'temp_min' => 12,
                'temp_max' => 19,
                'extra' => 'not declared',
            ]],
        ]);

        self::assertArrayNotHasKey('extra', $this->firstItemOfList('/api/weather', 'forecast'));
    }
}
