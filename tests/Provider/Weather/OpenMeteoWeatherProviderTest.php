<?php

declare(strict_types=1);

namespace App\Tests\Provider\Weather;

use App\Client\Weather\ForecastDay;
use App\Client\Weather\HourlyWeatherPoint;
use App\Client\Weather\WeatherIcon;
use App\Provider\Weather\OpenMeteoWeatherProvider;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoWeatherProviderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string OPEN_METEO_BASE_URI = 'https://api.open-meteo.com/v1/';
    private const string METRIC_CONFIG_FILE = 'pixelcast.yaml';
    private const string IMPERIAL_CONFIG_FILE = 'pixelcast-imperial.yaml';

    public function testFetchWeatherBuildsPayloadFromFixture(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse(), self::OPEN_METEO_BASE_URI));

        $payload = $provider->fetchWeather();

        self::assertNotNull($payload);
        self::assertSame(WeatherIcon::PartlyDay, $payload->current->icon);
        self::assertSame(25, $payload->current->temperature);
        self::assertSame(52, $payload->current->humidityPercentage);
        self::assertSame(16, $payload->current->minimumTemperature);
        self::assertSame(27, $payload->current->maximumTemperature);

        self::assertSame(
            ['MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
            array_map(static fn (ForecastDay $forecastDay): string => $forecastDay->dayLabel, $payload->forecastDays),
        );
        self::assertSame(
            [WeatherIcon::Cloudy, WeatherIcon::Rain, WeatherIcon::Rain, WeatherIcon::Thunder, WeatherIcon::Snow, WeatherIcon::Fog],
            array_map(static fn (ForecastDay $forecastDay): WeatherIcon => $forecastDay->icon, $payload->forecastDays),
        );
        self::assertSame(
            [16, 14, 14, 16, 10, 13],
            array_map(static fn (ForecastDay $forecastDay): int => $forecastDay->minimumTemperature, $payload->forecastDays),
        );
        self::assertSame(
            [25, 23, 24, 26, 20, 21],
            array_map(static fn (ForecastDay $forecastDay): int => $forecastDay->maximumTemperature, $payload->forecastDays),
        );
    }

    public function testForecastStartsTomorrowSoTodayIsNotRepeatedUnderTheCurrentConditions(): void
    {
        $payload = $this->buildProvider(new MockHttpClient(self::fixtureResponse(), self::OPEN_METEO_BASE_URI))->fetchWeather();

        self::assertNotNull($payload);
        self::assertNotSame('LUN', $payload->forecastDays[0]->dayLabel);
        self::assertSame($payload->current->minimumTemperature, 16);
        self::assertSame($payload->current->maximumTemperature, 27);
    }

    public function testHourlyWindowCarriesTwelveAbsoluteLocalHours(): void
    {
        $payload = $this->buildProvider(new MockHttpClient(self::fixtureResponse(), self::OPEN_METEO_BASE_URI))->fetchWeather();

        self::assertNotNull($payload);
        self::assertSame(
            [14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 0, 1],
            array_map(static fn (HourlyWeatherPoint $hourlyPoint): int => $hourlyPoint->hourOfDay, $payload->hourlyWindow),
        );
        self::assertSame(
            [25, 25, 25, 24, 23, 21, 20, 19, 19, 18, 18, 18],
            array_map(static fn (HourlyWeatherPoint $hourlyPoint): int => $hourlyPoint->temperature, $payload->hourlyWindow),
        );
        self::assertSame(
            [0, 10, 40, 80, 65, 30, 10, 0, 0, null, 5, 5],
            array_map(static fn (HourlyWeatherPoint $hourlyPoint): ?int => $hourlyPoint->precipitationProbabilityPercentage, $payload->hourlyWindow),
        );
    }

    public function testThePayloadDeclaresTheSilenceDerivedFromTheGroupInterval(): void
    {
        $payload = $this->buildProvider(new MockHttpClient(self::fixtureResponse(), self::OPEN_METEO_BASE_URI))->fetchWeather();

        self::assertNotNull($payload);
        self::assertSame(5400, $payload->staleAfterInSeconds);
        self::assertNull($payload->staleBehavior);
    }

    public function testHourlyPrecipitationIsConvertedToTenthsOfMillimetreAndCappedAtTheDeviceLimit(): void
    {
        $payload = $this->buildProvider(new MockHttpClient(self::fixtureResponse(), self::OPEN_METEO_BASE_URI))->fetchWeather();

        self::assertNotNull($payload);
        self::assertSame(
            [0, 2, 8, 25, 11, 3, 0, 0, 0, 0, 255, 0],
            array_map(static fn (HourlyWeatherPoint $hourlyPoint): ?int => $hourlyPoint->precipitationInTenthsOfMillimetre, $payload->hourlyWindow),
        );
    }

    public function testMissingHourlyBlockLeavesTheRestOfThePayloadUsable(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(new MockResponse(self::forecastWithoutHourlyBlock()), self::OPEN_METEO_BASE_URI);

        $payload = $this->buildProvider($httpClient, $logger)->fetchWeather();

        self::assertNotNull($payload);
        self::assertSame([], $payload->hourlyWindow);
        self::assertCount(6, $payload->forecastDays);
        self::assertSame(['Unexpected Open-Meteo response shape'], array_column($logger->records, 'message'));
    }

    public function testRequestTargetsOpenMeteoForecastEndpointWithExpectedQuery(): void
    {
        $response = self::fixtureResponse();

        $this->buildProvider(new MockHttpClient($response, self::OPEN_METEO_BASE_URI))->fetchWeather();

        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringStartsWith(self::OPEN_METEO_BASE_URI.'forecast?', $response->getRequestUrl());

        $queryParameters = self::queryParameters($response);
        self::assertSame('48.8566', $queryParameters['latitude'] ?? null);
        self::assertSame('2.3522', $queryParameters['longitude'] ?? null);
        self::assertSame('temperature_2m,relative_humidity_2m,weather_code,is_day', $queryParameters['current'] ?? null);
        self::assertSame('weather_code,temperature_2m_max,temperature_2m_min', $queryParameters['daily'] ?? null);
        self::assertSame('temperature_2m,precipitation_probability,precipitation', $queryParameters['hourly'] ?? null);
        self::assertSame('7', $queryParameters['forecast_days'] ?? null);
        self::assertSame('12', $queryParameters['forecast_hours'] ?? null);
        self::assertSame('auto', $queryParameters['timezone'] ?? null);
        self::assertSame('celsius', $queryParameters['temperature_unit'] ?? null);
    }

    public function testImperialUnitsRequestFahrenheit(): void
    {
        $response = self::fixtureResponse();

        $this->buildProvider(new MockHttpClient($response, self::OPEN_METEO_BASE_URI), configFileName: self::IMPERIAL_CONFIG_FILE)->fetchWeather();

        self::assertSame('fahrenheit', self::queryParameters($response)['temperature_unit'] ?? null);
    }

    public function testEnglishLocaleProducesEnglishDayLabels(): void
    {
        $provider = $this->buildProvider(
            new MockHttpClient(self::fixtureResponse(), self::OPEN_METEO_BASE_URI),
            configFileName: self::IMPERIAL_CONFIG_FILE,
        );

        $payload = $provider->fetchWeather();

        self::assertNotNull($payload);
        self::assertSame(
            ['TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'],
            array_map(static fn (ForecastDay $forecastDay): string => $forecastDay->dayLabel, $payload->forecastDays),
        );
    }

    public function testSecondCallIsServedFromCache(): void
    {
        $httpClient = new MockHttpClient(self::fixtureResponse(), self::OPEN_METEO_BASE_URI);
        $provider = $this->buildProvider($httpClient);

        self::assertNotNull($provider->fetchWeather());
        self::assertNotNull($provider->fetchWeather());
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testTransportErrorReturnsNullAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']), self::OPEN_METEO_BASE_URI);

        self::assertNull($this->buildProvider($httpClient, $logger)->fetchWeather());
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Open-Meteo request failed'], array_column($logger->records, 'message'));
    }

    public function testFailureIsNotCached(): void
    {
        $httpClient = new MockHttpClient(
            [new MockResponse('', ['error' => 'connection refused']), self::fixtureResponse()],
            self::OPEN_METEO_BASE_URI,
        );
        $provider = $this->buildProvider($httpClient);

        self::assertNull($provider->fetchWeather());
        self::assertNotNull($provider->fetchWeather());
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testUnknownWeatherCodeFallsBackToCloudyAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(new MockResponse(self::forecastWithCurrentWeatherCode(4)), self::OPEN_METEO_BASE_URI);

        $payload = $this->buildProvider($httpClient, $logger)->fetchWeather();

        self::assertNotNull($payload);
        self::assertSame(WeatherIcon::Cloudy, $payload->current->icon);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Unknown WMO weather code'], array_column($logger->records, 'message'));
        self::assertSame(4, $logger->records[0]['context']['weather_code'] ?? null);
    }

    public function testMalformedResponseShapeReturnsNull(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(new MockResponse(self::forecastWithoutDailyBlock()), self::OPEN_METEO_BASE_URI);

        self::assertNull($this->buildProvider($httpClient, $logger)->fetchWeather());
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Unexpected Open-Meteo response shape'], array_column($logger->records, 'message'));
    }

    private function buildProvider(
        MockHttpClient $httpClient,
        ?LoggerInterface $logger = null,
        string $configFileName = self::METRIC_CONFIG_FILE,
    ): OpenMeteoWeatherProvider {
        return new OpenMeteoWeatherProvider(
            $httpClient,
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
            new ArrayAdapter(),
            $logger ?? new NullLogger(),
        );
    }

    private static function fixtureResponse(): MockResponse
    {
        return new MockResponse(self::rawFixture());
    }

    private static function forecastWithCurrentWeatherCode(int $weatherCode): string
    {
        $forecast = self::decodedFixture();
        $currentBlock = $forecast['current'] ?? null;
        if (!\is_array($currentBlock)) {
            self::fail('The Open-Meteo fixture carries no current block.');
        }

        $currentBlock['weather_code'] = $weatherCode;
        $forecast['current'] = $currentBlock;

        return self::encodeForecast($forecast);
    }

    private static function forecastWithoutDailyBlock(): string
    {
        $forecast = self::decodedFixture();
        unset($forecast['daily']);

        return self::encodeForecast($forecast);
    }

    private static function forecastWithoutHourlyBlock(): string
    {
        $forecast = self::decodedFixture();
        unset($forecast['hourly']);

        return self::encodeForecast($forecast);
    }

    private static function rawFixture(): string
    {
        $rawJson = file_get_contents(self::FIXTURES_DIR.'/open-meteo-forecast.json');
        if (false === $rawJson) {
            self::fail('The Open-Meteo fixture could not be read.');
        }

        return $rawJson;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedFixture(): array
    {
        $decoded = json_decode(self::rawFixture(), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            self::fail('The Open-Meteo fixture is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $forecast
     */
    private static function encodeForecast(array $forecast): string
    {
        return json_encode($forecast, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function queryParameters(MockResponse $response): array
    {
        $queryString = parse_url($response->getRequestUrl(), \PHP_URL_QUERY);
        if (!\is_string($queryString)) {
            self::fail('The Open-Meteo request carries no query string.');
        }

        parse_str($queryString, $queryParameters);

        return $queryParameters;
    }
}
