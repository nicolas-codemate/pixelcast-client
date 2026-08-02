<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;

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

        $this->buildPixelcastClient()->pushWeather($payload);

        $loggedRequests = self::loggedRequests($this->inspect());
        self::assertCount(1, $loggedRequests, $this->server->serverOutput());

        $weatherRequest = $loggedRequests[0] ?? [];
        self::assertSame('POST', $weatherRequest['method'] ?? null);
        self::assertSame('/api/weather', $weatherRequest['path'] ?? null);
        self::assertSame(['valid' => true], $weatherRequest['validation'] ?? null);
        self::assertSame($payload->toArray(), $weatherRequest['body'] ?? null);
    }
}
