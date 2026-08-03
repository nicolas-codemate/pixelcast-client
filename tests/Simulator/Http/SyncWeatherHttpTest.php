<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Config\SyncsConfigLoader;
use App\Message\SyncOutcome;
use App\Message\SyncWeatherMessage;
use App\MessageHandler\SyncWeatherHandler;
use App\Provider\Weather\OpenMeteoWeatherProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyncWeatherHttpTest extends SimulatorHttpTestCase
{
    private const string OPEN_METEO_BASE_URI = 'https://api.open-meteo.com/v1/';
    private const int EXPECTED_FORECAST_DAYS = 7;

    public function testProviderPayloadReachesTheSimulatorUnchanged(): void
    {
        $weatherProvider = $this->buildWeatherProvider();

        $expectedPayload = $weatherProvider->fetchWeather();
        self::assertNotNull($expectedPayload);
        self::assertCount(self::EXPECTED_FORECAST_DAYS, $expectedPayload->forecastDays);

        $syncWeatherHandler = new SyncWeatherHandler($weatherProvider, $this->buildPixelcastClient(), new NullLogger());

        self::assertSame(SyncOutcome::Pushed, $syncWeatherHandler(new SyncWeatherMessage()), $this->server->serverOutput());

        $inspectPayload = $this->inspect();
        $expectedBody = $expectedPayload->toArray();

        $loggedRequests = self::loggedRequests($inspectPayload);
        self::assertCount(1, $loggedRequests, $this->server->serverOutput());

        $weatherRequest = $loggedRequests[0] ?? [];
        self::assertSame('POST', $weatherRequest['method'] ?? null);
        self::assertSame('/api/weather', $weatherRequest['path'] ?? null);
        self::assertSame(['valid' => true], $weatherRequest['validation'] ?? null);
        self::assertSame($expectedBody, $weatherRequest['body'] ?? null);

        $weatherState = self::domainState($inspectPayload, 'weather');
        self::assertTrue($weatherState['valid'] ?? null, $this->server->serverOutput());
        self::assertSame($expectedBody['current'], $weatherState['current'] ?? null);
        self::assertSame($expectedBody['forecast'] ?? [], $weatherState['forecast'] ?? null);
        self::assertNotNull($weatherState['lastUpdatedAt'] ?? null);
    }

    private function buildWeatherProvider(): OpenMeteoWeatherProvider
    {
        $weatherFixturesDirectory = \dirname(__DIR__, 2).'/Provider/Weather/Fixtures';

        // A single upstream response: the payload pushed by the handler can only be the one
        // built above and kept in the provider cache.
        return new OpenMeteoWeatherProvider(
            new MockHttpClient(
                MockResponse::fromFile($weatherFixturesDirectory.'/open-meteo-forecast.json'),
                self::OPEN_METEO_BASE_URI,
            ),
            new SyncsConfigLoader($weatherFixturesDirectory.'/pixelcast.yaml', \dirname(__DIR__, 3).'/pixelcast.schema.json'),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }
}
