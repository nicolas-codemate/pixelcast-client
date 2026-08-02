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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PixelcastClientTest extends TestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://device.test/api';
    private const string EXPECTED_WEATHER_URL = 'http://device.test/api/weather';

    // Parsing the vendored spec costs more than every test of this class combined.
    private static RequestValidator $requestValidator;

    private OutboundPayloadValidator $outboundPayloadValidator;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$requestValidator = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 2), self::TEST_DEVICE_BASE_URL)->create();
    }

    /**
     * @return iterable<string, array{int, string, class-string<PixelcastClientException>, string}>
     */
    public static function failureStatusProvider(): iterable
    {
        yield 'bad request carries the device message' => [400, '{"error":"bad temp"}', InvalidPayloadException::class, '/bad temp/'];
        yield 'not found names the path' => [404, '{"error":"unknown route"}', ResourceNotFoundException::class, '#/weather#'];
        yield 'no free slot' => [500, '{"error":"no free slot"}', DeviceBusyException::class, '/500/'];
        yield 'queue full' => [503, '{"error":"queue full"}', DeviceBusyException::class, '/503/'];
        yield 'unmapped status' => [418, 'teapot', DeviceUnreachableException::class, '/418/'];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function busyStatusProvider(): iterable
    {
        yield 'slot exhausted' => [500];
        yield 'queue full' => [503];
    }

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * @param class-string<PixelcastClientException> $expectedException
     */
    #[DataProvider('failureStatusProvider')]
    public function testFailureStatusMapsToADedicatedException(int $httpStatus, string $responseBody, string $expectedException, string $expectedMessagePattern): void
    {
        $client = $this->buildClient(new MockResponse($responseBody, ['http_code' => $httpStatus]));

        $this->expectException($expectedException);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $client->pushWeather(self::buildPayload());
    }

    #[DataProvider('busyStatusProvider')]
    public function testDeviceBusyExceptionKeepsTheHttpStatusApart(int $httpStatus): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"busy"}', ['http_code' => $httpStatus]));

        try {
            $client->pushWeather(self::buildPayload());
        } catch (DeviceBusyException $deviceBusy) {
            self::assertSame($httpStatus, $deviceBusy->httpStatus);

            return;
        }

        self::fail(\sprintf('The client accepted an HTTP %d answer.', $httpStatus));
    }

    public function testTransportFailureThrowsDeviceUnreachableException(): void
    {
        $client = $this->buildClient(new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(DeviceUnreachableException::class);
        $this->expectExceptionMessageMatches('/connection refused/');

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
        return new OutboundPayloadValidator(self::$requestValidator, new Psr17Factory(), $deviceBaseUrl);
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
