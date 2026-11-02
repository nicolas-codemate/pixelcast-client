<?php

declare(strict_types=1);

namespace App\Tests\Provider\HomeAssistant;

use App\Provider\HomeAssistant\HomeAssistantSensorProvider;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HomeAssistantSensorProviderTest extends TestCase
{
    private const string BASE_URL = 'http://host.docker.internal:8123';
    private const string CONFIG_FIXTURE = 'tests/Config/Fixtures/syncs-homeassistant-enabled.yaml';
    private const string SINGLE_SENSOR_CONFIG_FIXTURE = 'tests/Config/Fixtures/syncs-homeassistant-single-sensor.yaml';
    private const string DEVICE_BASE_URL = 'http://simulator:8080/api';
    private const string TOKEN = 'ha-test-token';

    public function testEachSensorIsDrawnAsOneZoneInTheOrderOfTheFile(): void
    {
        $customApp = $this->buildProvider(self::homeAssistantClient([
            self::stateResponse('21.3', '°C'),
            self::stateResponse('8.7', '°C'),
        ]))->fetchSensorsCustomApp();

        self::assertNotNull($customApp);
        self::assertSame('homeassistant', $customApp->name);
        self::assertSame(
            [
                'zones' => [
                    ['text' => '21.3°', 'icon' => 'thermo', 'label' => 'Salon', 'color' => '#00D4FF'],
                    ['text' => '8.7°', 'label' => 'Dehors'],
                ],
                'staleAfter' => 1800,
                'staleBehavior' => 'hide',
            ],
            $customApp->toArray(),
        );
    }

    public function testEachStateIsRequestedWithTheToken(): void
    {
        $salonResponse = self::stateResponse('21.3', '°C');
        $outsideResponse = self::stateResponse('8.7', '°C');

        $this->buildProvider(self::homeAssistantClient([$salonResponse, $outsideResponse]), baseUrl: self::BASE_URL.'/')->fetchSensorsCustomApp();

        self::assertSame('GET', $salonResponse->getRequestMethod());
        self::assertSame(self::BASE_URL.'/api/states/sensor.salon_temperature', $salonResponse->getRequestUrl());
        self::assertSame(self::BASE_URL.'/api/states/sensor.exterieur_temperature', $outsideResponse->getRequestUrl());
        self::assertContains('Authorization: Bearer '.self::TOKEN, self::requestHeaders($salonResponse));
    }

    public function testALoneSensorIsDrawnAsASingleZoneApp(): void
    {
        $customApp = $this->buildProvider(
            self::homeAssistantClient([self::stateResponse('21.3', '°C')]),
            configFixture: self::SINGLE_SENSOR_CONFIG_FIXTURE,
        )->fetchSensorsCustomApp();

        self::assertNotNull($customApp);
        self::assertSame(['text' => '21.3°', 'label' => 'Salon', 'staleAfter' => 1800], $customApp->toArray());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideStatesAndHowTheyAreDrawn(): iterable
    {
        yield 'a celsius temperature keeps the degree sign alone' => ['21.3', '°C', '21.3°'];
        yield 'a fahrenheit temperature keeps the degree sign alone' => ['70.5', '°F', '70.5°'];
        yield 'a long decimal is rounded to one place' => ['21.349999', '°C', '21.3°'];
        yield 'a round value loses its trailing zero' => ['21.0', '°C', '21°'];
        yield 'a below-zero temperature keeps its sign' => ['-3.2', '°C', '-3.2°'];
        yield 'any other unit is drawn as reported' => ['54', '%', '54%'];
        yield 'a value without unit is drawn bare' => ['412', '', '412'];
    }

    #[DataProvider('provideStatesAndHowTheyAreDrawn')]
    public function testTheStateIsRoundedAndFollowedByItsUnit(string $state, string $unit, string $expectedText): void
    {
        $customApp = $this->buildProvider(
            self::homeAssistantClient([self::stateResponse($state, $unit)]),
            configFixture: self::SINGLE_SENSOR_CONFIG_FIXTURE,
        )->fetchSensorsCustomApp();

        self::assertSame($expectedText, $customApp?->toArray()['text'] ?? null);
    }

    public function testAnUnavailableSensorKeepsItsZoneWithAPlaceholder(): void
    {
        $logger = new RecordingLoggerStub();

        $customApp = $this->buildProvider(self::homeAssistantClient([
            self::stateResponse('unavailable', '°C'),
            self::stateResponse('8.7', '°C'),
        ]), $logger)->fetchSensorsCustomApp();

        self::assertNotNull($customApp);
        self::assertSame(['--', '8.7°'], array_column($customApp->toArray()['zones'] ?? [], 'text'));
        self::assertStringContainsString('no numeric value', self::loggedText($logger));
    }

    public function testAnUnreachableSensorKeepsItsZoneWithAPlaceholder(): void
    {
        $logger = new RecordingLoggerStub();

        $customApp = $this->buildProvider(self::homeAssistantClient([
            self::stateResponse('21.3', '°C'),
            new MockResponse('{"message": "Entity not found."}', ['http_code' => 404]),
        ]), $logger)->fetchSensorsCustomApp();

        self::assertNotNull($customApp);
        self::assertSame(['21.3°', '--'], array_column($customApp->toArray()['zones'] ?? [], 'text'));
        self::assertStringContainsString('sensor.exterieur_temperature', self::loggedText($logger));
    }

    public function testNoReadableSensorLeavesTheScreenAlone(): void
    {
        $customApp = $this->buildProvider(self::homeAssistantClient([
            new MockResponse('', ['error' => 'connection refused']),
            new MockResponse('', ['error' => 'connection refused']),
        ]))->fetchSensorsCustomApp();

        self::assertNull($customApp);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function provideMissingSettingsAndTheVariableTheyName(): iterable
    {
        yield 'no address' => [null, self::TOKEN, 'PIXELCAST_HOMEASSISTANT_URL'];
        yield 'an empty address' => ['', self::TOKEN, 'PIXELCAST_HOMEASSISTANT_URL'];
        yield 'no token' => [self::BASE_URL, null, 'PIXELCAST_HOMEASSISTANT_TOKEN'];
        yield 'an empty token' => [self::BASE_URL, '', 'PIXELCAST_HOMEASSISTANT_TOKEN'];
    }

    #[DataProvider('provideMissingSettingsAndTheVariableTheyName')]
    public function testAMissingSettingNamesItsEnvironmentVariableAndSpendsNoRequest(?string $baseUrl, ?string $token, string $expectedVariable): void
    {
        $logger = new RecordingLoggerStub();
        $homeAssistantClient = self::homeAssistantClient([self::stateResponse('21.3', '°C')]);

        $customApp = $this->buildProvider($homeAssistantClient, $logger, baseUrl: $baseUrl, token: $token)->fetchSensorsCustomApp();

        self::assertNull($customApp);
        self::assertSame(0, $homeAssistantClient->getRequestsCount());
        self::assertSame(['environment_variable' => $expectedVariable], $logger->records[0]['context'] ?? null);
    }

    public function testTheProducedPayloadIsAcceptedByTheDeviceSpecification(): void
    {
        $customApp = $this->buildProvider(self::homeAssistantClient([
            self::stateResponse('21.3', '°C'),
            self::stateResponse('unavailable', '°C'),
        ]))->fetchSensorsCustomApp();

        self::assertNotNull($customApp);

        $validatorFactory = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 3), self::DEVICE_BASE_URL);
        $validator = new OutboundPayloadValidator($validatorFactory->create(), new Psr17Factory(), self::DEVICE_BASE_URL);

        $result = $validator->validateRequest('POST', '/custom', ['name' => $customApp->name], $customApp->toArray());

        self::assertTrue($result->valid, $result->errorMessage ?? '');
    }

    private function buildProvider(
        MockHttpClient $homeAssistantClient,
        ?RecordingLoggerStub $logger = null,
        string $configFixture = self::CONFIG_FIXTURE,
        ?string $baseUrl = self::BASE_URL,
        ?string $token = self::TOKEN,
    ): HomeAssistantSensorProvider {
        return new HomeAssistantSensorProvider(
            $homeAssistantClient,
            SyncsConfigLoaderFactory::forConfigFile(SyncsConfigLoaderFactory::projectFilePath($configFixture)),
            $logger ?? new RecordingLoggerStub(),
            $baseUrl,
            $token,
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private static function homeAssistantClient(array $responses): MockHttpClient
    {
        return new MockHttpClient($responses);
    }

    private static function stateResponse(string $state, string $unit): MockResponse
    {
        $attributes = '' === $unit ? [] : ['unit_of_measurement' => $unit];

        return new MockResponse(json_encode([
            'entity_id' => 'sensor.any',
            'state' => $state,
            'attributes' => $attributes,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private static function requestHeaders(MockResponse $response): array
    {
        $headers = $response->getRequestOptions()['headers'] ?? [];
        if (!\is_array($headers)) {
            self::fail('The state request carries no readable headers.');
        }

        $stringHeaders = [];
        foreach ($headers as $header) {
            if (\is_string($header)) {
                $stringHeaders[] = $header;
            }
        }

        return $stringHeaders;
    }

    private static function loggedText(RecordingLoggerStub $logger): string
    {
        return json_encode($logger->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
