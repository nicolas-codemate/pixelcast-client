<?php

declare(strict_types=1);

namespace App\Tests\Provider\Weather;

use App\Client\Weather\WeatherIcon;
use App\Provider\Weather\WmoWeatherCodeIconMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WmoWeatherCodeIconMapperTest extends TestCase
{
    private const int DOCUMENTED_WEATHER_CODE_COUNT = 28;

    /**
     * @return iterable<string, array{int, WeatherIcon}>
     */
    public static function provideDaytimeWeatherCodeCases(): iterable
    {
        yield 'clear sky' => [0, WeatherIcon::ClearDay];
        yield 'mainly clear' => [1, WeatherIcon::ClearDay];
        yield 'partly cloudy' => [2, WeatherIcon::PartlyDay];
        yield 'overcast' => [3, WeatherIcon::Cloudy];
        yield 'fog' => [45, WeatherIcon::Fog];
        yield 'depositing rime fog' => [48, WeatherIcon::Fog];
        yield 'light drizzle' => [51, WeatherIcon::Rain];
        yield 'moderate drizzle' => [53, WeatherIcon::Rain];
        yield 'dense drizzle' => [55, WeatherIcon::Rain];
        yield 'light freezing drizzle' => [56, WeatherIcon::Rain];
        yield 'dense freezing drizzle' => [57, WeatherIcon::Rain];
        yield 'slight rain' => [61, WeatherIcon::Rain];
        yield 'moderate rain' => [63, WeatherIcon::Rain];
        yield 'heavy rain' => [65, WeatherIcon::HeavyRain];
        yield 'light freezing rain' => [66, WeatherIcon::Rain];
        yield 'heavy freezing rain' => [67, WeatherIcon::HeavyRain];
        yield 'slight snow fall' => [71, WeatherIcon::Snow];
        yield 'moderate snow fall' => [73, WeatherIcon::Snow];
        yield 'heavy snow fall' => [75, WeatherIcon::Snow];
        yield 'snow grains' => [77, WeatherIcon::Snow];
        yield 'slight rain showers' => [80, WeatherIcon::Rain];
        yield 'moderate rain showers' => [81, WeatherIcon::Rain];
        yield 'violent rain showers' => [82, WeatherIcon::HeavyRain];
        yield 'slight snow showers' => [85, WeatherIcon::Snow];
        yield 'heavy snow showers' => [86, WeatherIcon::Snow];
        yield 'thunderstorm' => [95, WeatherIcon::Thunder];
        yield 'thunderstorm with slight hail' => [96, WeatherIcon::Thunder];
        yield 'thunderstorm with heavy hail' => [99, WeatherIcon::Thunder];
    }

    #[DataProvider('provideDaytimeWeatherCodeCases')]
    public function testDaytimeWeatherCodeMapsToTheExpectedIcon(int $weatherCode, WeatherIcon $expectedIcon): void
    {
        self::assertSame($expectedIcon, WmoWeatherCodeIconMapper::mapToIcon($weatherCode, isDay: true));
    }

    /**
     * @return iterable<string, array{int, WeatherIcon}>
     */
    public static function provideNighttimeWeatherCodeCases(): iterable
    {
        yield 'clear sky' => [0, WeatherIcon::ClearNight];
        yield 'mainly clear' => [1, WeatherIcon::ClearNight];
        yield 'partly cloudy' => [2, WeatherIcon::PartlyNight];
    }

    #[DataProvider('provideNighttimeWeatherCodeCases')]
    public function testNighttimeWeatherCodeMapsToTheNightVariant(int $weatherCode, WeatherIcon $expectedIcon): void
    {
        self::assertSame($expectedIcon, WmoWeatherCodeIconMapper::mapToIcon($weatherCode, isDay: false));
    }

    public function testWeatherCodesWithoutANightVariantKeepTheSameIconAtNight(): void
    {
        $dayNightSensitiveWeatherCodes = [0, 1, 2];

        foreach (self::provideDaytimeWeatherCodeCases() as [$weatherCode, $expectedIcon]) {
            if (\in_array($weatherCode, $dayNightSensitiveWeatherCodes, true)) {
                continue;
            }

            self::assertSame($expectedIcon, WmoWeatherCodeIconMapper::mapToIcon($weatherCode, isDay: false));
        }
    }

    public function testEveryDocumentedWeatherCodeIsMappedToAnIcon(): void
    {
        $mappedWeatherCodes = [];

        foreach (self::provideDaytimeWeatherCodeCases() as [$weatherCode]) {
            self::assertNotNull(WmoWeatherCodeIconMapper::mapToIcon($weatherCode, isDay: true));
            $mappedWeatherCodes[$weatherCode] = true;
        }

        self::assertCount(self::DOCUMENTED_WEATHER_CODE_COUNT, $mappedWeatherCodes);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideUndocumentedWeatherCodeCases(): iterable
    {
        yield 'gap in the table' => [4];
        yield 'unused fog range' => [42];
        yield 'above the table' => [100];
        yield 'negative' => [-1];
    }

    #[DataProvider('provideUndocumentedWeatherCodeCases')]
    public function testUndocumentedWeatherCodeReturnsNull(int $weatherCode): void
    {
        self::assertNull(WmoWeatherCodeIconMapper::mapToIcon($weatherCode, isDay: true));
        self::assertNull(WmoWeatherCodeIconMapper::mapToIcon($weatherCode, isDay: false));
    }
}
