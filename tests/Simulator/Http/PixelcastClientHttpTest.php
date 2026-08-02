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
use App\Simulator\State\PersistedStateReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Response;

final class PixelcastClientHttpTest extends TestCase
{
    private SimulatorHttpServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = SimulatorHttpServer::start(\dirname(__DIR__, 3));
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }

        parent::tearDown();
    }

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

        $loggedRequests = PersistedStateReader::payloadList($this->inspect()['requests'] ?? null);
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

    /**
     * @return array<string, mixed>
     */
    private function inspect(): array
    {
        $inspectResponse = $this->server->get('/api/__inspect');
        self::assertSame(Response::HTTP_OK, $inspectResponse->statusCode, $inspectResponse->body."\n".$this->server->serverOutput());

        return $inspectResponse->decodedBody();
    }
}
