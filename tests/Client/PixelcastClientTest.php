<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\Exception\DeviceBusyException;
use App\Client\Exception\DeviceUnreachableException;
use App\Client\Exception\InvalidPayloadException;
use App\Client\Exception\PixelcastClientException;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\PixelcastClient;
use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use League\OpenAPIValidation\PSR7\RequestValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PixelcastClientTest extends TestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://device.test/api';
    private const string EXPECTED_WEATHER_URL = 'http://device.test/api/weather';

    private RequestValidator $requestValidator;
    private OutboundPayloadValidator $outboundPayloadValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestValidator = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 2), self::TEST_DEVICE_BASE_URL)->create();
        $this->outboundPayloadValidator = $this->buildOutboundPayloadValidator(self::TEST_DEVICE_BASE_URL);
    }

    public function testSuccessfulPushSendsTheSerializedPayloadToTheWeatherEndpoint(): void
    {
        $response = new MockResponse('{"success":true}');
        $payload = self::buildPayload();

        $this->buildClient($response)->pushWeather($payload);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_WEATHER_URL, $response->getRequestUrl());
        self::assertSame($payload->toArray(), self::decodedRequestBody($response));
    }

    public function testLocallyRejectedPayloadIsNotSentAtAll(): void
    {
        // A device base URL without the /api prefix builds a path the spec does not declare.
        $misconfiguredClient = new MockHttpClient(new MockResponse('{"success":true}'), self::TEST_DEVICE_BASE_URL.'/');
        $client = new PixelcastClient($misconfiguredClient, $this->buildOutboundPayloadValidator('http://device.test'));

        try {
            $client->pushWeather(self::buildPayload());
        } catch (InvalidPayloadException $invalidPayload) {
            self::assertStringContainsString('rejected before sending', $invalidPayload->getMessage());
            self::assertSame(0, $misconfiguredClient->getRequestsCount());

            return;
        }

        self::fail('The client sent a payload its own validator rejects.');
    }

    public function testBadRequestThrowsInvalidPayloadExceptionCarryingTheDeviceMessage(): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"bad temp"}', ['http_code' => 400]));

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessageMatches('/bad temp/');

        $client->pushWeather(self::buildPayload());
    }

    public function testNotFoundThrowsResourceNotFoundException(): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"unknown route"}', ['http_code' => 404]));

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('#/weather#');

        $client->pushWeather(self::buildPayload());
    }

    public function testInternalErrorThrowsDeviceBusyExceptionWithStatus500(): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"no free slot"}', ['http_code' => 500]));

        try {
            $client->pushWeather(self::buildPayload());
        } catch (DeviceBusyException $deviceBusy) {
            self::assertSame(500, $deviceBusy->httpStatus);

            return;
        }

        self::fail('The client accepted an HTTP 500 answer.');
    }

    public function testServiceUnavailableThrowsDeviceBusyExceptionWithStatus503(): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"queue full"}', ['http_code' => 503]));

        try {
            $client->pushWeather(self::buildPayload());
        } catch (DeviceBusyException $deviceBusy) {
            self::assertSame(503, $deviceBusy->httpStatus);

            return;
        }

        self::fail('The client accepted an HTTP 503 answer.');
    }

    public function testTransportFailureThrowsDeviceUnreachableException(): void
    {
        $client = $this->buildClient(new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(DeviceUnreachableException::class);
        $this->expectExceptionMessageMatches('/connection refused/');

        $client->pushWeather(self::buildPayload());
    }

    public function testUnmappedStatusThrowsDeviceUnreachableException(): void
    {
        $client = $this->buildClient(new MockResponse('teapot', ['http_code' => 418]));

        $this->expectException(DeviceUnreachableException::class);
        $this->expectExceptionMessageMatches('/418/');

        $client->pushWeather(self::buildPayload());
    }

    public function testMappedFailuresShareTheClientExceptionInterface(): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"bad temp"}', ['http_code' => 400]));

        $this->expectException(PixelcastClientException::class);

        $client->pushWeather(self::buildPayload());
    }

    private function buildClient(MockResponse $response): PixelcastClient
    {
        return new PixelcastClient(
            new MockHttpClient($response, self::TEST_DEVICE_BASE_URL.'/'),
            $this->outboundPayloadValidator,
        );
    }

    private function buildOutboundPayloadValidator(string $deviceBaseUrl): OutboundPayloadValidator
    {
        return new OutboundPayloadValidator($this->requestValidator, new Psr17Factory(), $deviceBaseUrl);
    }

    private static function buildPayload(): WeatherPayload
    {
        return new WeatherPayload(
            new CurrentWeather(WeatherIcon::Rain, 9, -2, 14, 80),
            [new ForecastDay('LUN', WeatherIcon::Cloudy, 4, 12)],
        );
    }

    private static function decodedRequestBody(MockResponse $response): mixed
    {
        $encodedRequestBody = $response->getRequestOptions()['body'] ?? null;

        if (!\is_string($encodedRequestBody)) {
            self::fail('The mock HTTP client received no string request body.');
        }

        return json_decode($encodedRequestBody, true);
    }
}
