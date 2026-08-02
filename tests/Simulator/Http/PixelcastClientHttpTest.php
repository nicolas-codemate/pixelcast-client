<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Client\PixelcastClient;
use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;

final class PixelcastClientHttpTest extends SimulatorHttpTestCase
{
    public function testPushedWeatherPayloadIsTheOneReceivedBySimulator(): void
    {
        $payload = new WeatherPayload(
            new CurrentWeather(WeatherIcon::HeavyRain, 9, -2, 14, 80),
            [
                new ForecastDay('LUN', WeatherIcon::Cloudy, 4, 12),
                new ForecastDay('MAR', WeatherIcon::Snow, -3, 2),
            ],
        );

        $this->buildClient()->pushWeather($payload);

        $loggedRequests = self::loggedRequests($this->inspect());
        self::assertCount(1, $loggedRequests, $this->server->serverOutput());

        $weatherRequest = $loggedRequests[0] ?? [];
        self::assertSame('POST', $weatherRequest['method'] ?? null);
        self::assertSame('/api/weather', $weatherRequest['path'] ?? null);
        self::assertSame(['valid' => true], $weatherRequest['validation'] ?? null);
        self::assertSame($payload->toArray(), $weatherRequest['body'] ?? null);
    }

    private function buildClient(): PixelcastClient
    {
        $deviceBaseUrl = $this->server->baseUrl.'/api';
        $validatorFactory = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 3), $deviceBaseUrl);

        return new PixelcastClient(
            HttpClient::createForBaseUri($deviceBaseUrl.'/'),
            new OutboundPayloadValidator($validatorFactory->create(), new Psr17Factory(), $deviceBaseUrl),
        );
    }
}
