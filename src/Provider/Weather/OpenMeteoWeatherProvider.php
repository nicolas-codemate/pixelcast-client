<?php

declare(strict_types=1);

namespace App\Provider\Weather;

use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Config\PixelCastConfigLoader;
use App\Config\WeatherLocale;
use App\Config\WeatherUnits;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenMeteoWeatherProvider implements WeatherProviderInterface
{
    private const string FORECAST_PATH = 'forecast';
    private const string CURRENT_FIELDS = 'temperature_2m,relative_humidity_2m,weather_code,is_day';
    private const string DAILY_FIELDS = 'weather_code,temperature_2m_max,temperature_2m_min';
    private const int FORECAST_DAY_COUNT = 7;
    private const int CACHE_TTL_IN_SECONDS = 1500;
    private const string CACHE_KEY_PREFIX = 'open_meteo_forecast_';
    private const string FORECAST_DATE_FORMAT = '!Y-m-d';

    public function __construct(
        #[Target('weather.client')]
        private HttpClientInterface $weatherClient,
        private PixelCastConfigLoader $configLoader,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchWeather(): ?WeatherPayload
    {
        $config = $this->configLoader->load();

        $rawForecast = $this->fetchRawForecast($config->weatherLatitude, $config->weatherLongitude, $config->weatherUnits);
        if (null === $rawForecast) {
            return null;
        }

        return $this->buildPayload($rawForecast, $config->weatherLocale);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRawForecast(float $latitude, float $longitude, WeatherUnits $weatherUnits): ?array
    {
        return $this->cache->get(
            self::buildCacheKey($latitude, $longitude, $weatherUnits),
            function (ItemInterface $cacheItem, bool &$shouldSave) use ($latitude, $longitude, $weatherUnits): ?array {
                $cacheItem->expiresAfter(self::CACHE_TTL_IN_SECONDS);

                $decodedForecast = $this->requestForecast($latitude, $longitude, $weatherUnits);
                if (null === $decodedForecast) {
                    // A two second network glitch must not leave the screen without weather for the next 25 minutes.
                    $shouldSave = false;
                }

                return $decodedForecast;
            },
        );
    }

    private static function buildCacheKey(float $latitude, float $longitude, WeatherUnits $weatherUnits): string
    {
        return self::CACHE_KEY_PREFIX.\sprintf('%.4F_%.4F_%s', $latitude, $longitude, $weatherUnits->value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestForecast(float $latitude, float $longitude, WeatherUnits $weatherUnits): ?array
    {
        try {
            /** @var array<string, mixed> $decodedForecast */
            $decodedForecast = $this->weatherClient->request('GET', self::FORECAST_PATH, [
                'query' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'current' => self::CURRENT_FIELDS,
                    'daily' => self::DAILY_FIELDS,
                    'forecast_days' => self::FORECAST_DAY_COUNT,
                    'timezone' => 'auto',
                    'temperature_unit' => self::temperatureUnitParameter($weatherUnits),
                ],
            ])->toArray();

            return $decodedForecast;
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Open-Meteo request failed', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }

    private static function temperatureUnitParameter(WeatherUnits $weatherUnits): string
    {
        return match ($weatherUnits) {
            WeatherUnits::Metric => 'celsius',
            WeatherUnits::Imperial => 'fahrenheit',
        };
    }

    /**
     * @param array<string, mixed> $rawForecast
     */
    private function buildPayload(array $rawForecast, WeatherLocale $weatherLocale): ?WeatherPayload
    {
        $currentBlock = $rawForecast['current'] ?? null;
        $dailyBlock = $rawForecast['daily'] ?? null;

        if (!\is_array($currentBlock) || !\is_array($dailyBlock)) {
            $this->logUnexpectedShape('the current or daily block is missing');

            return null;
        }

        $forecastDays = $this->buildForecastDays($dailyBlock, $weatherLocale);
        if (null === $forecastDays) {
            return null;
        }

        $currentWeather = $this->buildCurrentWeather($currentBlock, $forecastDays[0] ?? null);
        if (null === $currentWeather) {
            return null;
        }

        return new WeatherPayload($currentWeather, $forecastDays);
    }

    /**
     * @param array<array-key, mixed> $dailyBlock
     *
     * @return list<ForecastDay>|null
     */
    private function buildForecastDays(array $dailyBlock, WeatherLocale $weatherLocale): ?array
    {
        $dates = self::readStringSeries($dailyBlock, 'time');
        $weatherCodes = self::readNumberSeries($dailyBlock, 'weather_code');
        $maximumTemperatures = self::readNumberSeries($dailyBlock, 'temperature_2m_max');
        $minimumTemperatures = self::readNumberSeries($dailyBlock, 'temperature_2m_min');

        if (null === $dates || null === $weatherCodes || null === $maximumTemperatures || null === $minimumTemperatures) {
            $this->logUnexpectedShape('the daily block does not carry the four expected series');

            return null;
        }

        $dayCount = min(\count($dates), \count($weatherCodes), \count($maximumTemperatures), \count($minimumTemperatures));

        $forecastDays = [];
        for ($dayIndex = 0; $dayIndex < $dayCount; ++$dayIndex) {
            $date = \DateTimeImmutable::createFromFormat(self::FORECAST_DATE_FORMAT, $dates[$dayIndex]);
            if (false === $date) {
                $this->logUnexpectedShape(\sprintf('the forecast date "%s" is unreadable', $dates[$dayIndex]));

                return null;
            }

            $forecastDays[] = new ForecastDay(
                $weatherLocale->dayLabelFor($date),
                $this->resolveIcon((int) $weatherCodes[$dayIndex], isDay: true),
                (int) round($minimumTemperatures[$dayIndex]),
                (int) round($maximumTemperatures[$dayIndex]),
            );
        }

        return $forecastDays;
    }

    /**
     * @param array<array-key, mixed> $currentBlock
     */
    private function buildCurrentWeather(array $currentBlock, ?ForecastDay $todayForecast): ?CurrentWeather
    {
        $weatherCode = self::readNumber($currentBlock, 'weather_code');
        $isDayFlag = self::readNumber($currentBlock, 'is_day');
        $temperature = self::readNumber($currentBlock, 'temperature_2m');
        $humidityPercentage = self::readNumber($currentBlock, 'relative_humidity_2m');

        if (null === $weatherCode || null === $isDayFlag || null === $temperature || null === $humidityPercentage) {
            $this->logUnexpectedShape('the current block does not carry the four expected measurements');

            return null;
        }

        return new CurrentWeather(
            $this->resolveIcon((int) $weatherCode, 1 === (int) $isDayFlag),
            (int) round($temperature),
            $todayForecast?->minimumTemperature,
            $todayForecast?->maximumTemperature,
            (int) round($humidityPercentage),
        );
    }

    private function resolveIcon(int $weatherCode, bool $isDay): WeatherIcon
    {
        $icon = WmoWeatherCodeIconMapper::mapToIcon($weatherCode, $isDay);
        if (null === $icon) {
            $this->logger->warning('Unknown WMO weather code', ['weather_code' => $weatherCode]);

            return WeatherIcon::Cloudy;
        }

        return $icon;
    }

    private function logUnexpectedShape(string $reason): void
    {
        $this->logger->warning('Unexpected Open-Meteo response shape', ['reason' => $reason]);
    }

    /**
     * @param array<array-key, mixed> $block
     */
    private static function readNumber(array $block, string $key): ?float
    {
        $value = $block[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<array-key, mixed> $block
     *
     * @return list<float>|null
     */
    private static function readNumberSeries(array $block, string $key): ?array
    {
        $series = $block[$key] ?? null;
        if (!\is_array($series)) {
            return null;
        }

        $numbers = [];
        foreach ($series as $value) {
            if (!is_numeric($value)) {
                return null;
            }

            $numbers[] = (float) $value;
        }

        return $numbers;
    }

    /**
     * @param array<array-key, mixed> $block
     *
     * @return list<string>|null
     */
    private static function readStringSeries(array $block, string $key): ?array
    {
        $series = $block[$key] ?? null;
        if (!\is_array($series)) {
            return null;
        }

        $strings = [];
        foreach ($series as $value) {
            if (!\is_string($value)) {
                return null;
            }

            $strings[] = $value;
        }

        return $strings;
    }
}
