<?php

declare(strict_types=1);

namespace App\Tests\Client\Weather;

use App\Client\Weather\CurrentWeather;
use App\Client\Weather\WeatherIcon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrentWeatherTest extends TestCase
{
    public function testToArrayEmitsEveryOptionalFieldWhenProvided(): void
    {
        $current = new CurrentWeather(
            icon: WeatherIcon::PartlyDay,
            temperature: 18,
            minimumTemperature: 11,
            maximumTemperature: 24,
            humidityPercentage: 63,
        );

        self::assertSame(
            [
                'icon' => 'w_partly_day',
                'temp' => 18,
                'temp_min' => 11,
                'temp_max' => 24,
                'humidity' => 63,
            ],
            $current->toArray(),
        );
    }

    public function testToArrayOmitsTheOptionalFieldsLeftNull(): void
    {
        $current = new CurrentWeather(icon: WeatherIcon::Rain, temperature: 9);

        self::assertSame(['icon' => 'w_rain', 'temp' => 9], $current->toArray());
    }

    public function testToArrayKeepsAZeroHumidity(): void
    {
        $current = new CurrentWeather(icon: WeatherIcon::Fog, temperature: 2, humidityPercentage: 0);

        self::assertSame(['icon' => 'w_fog', 'temp' => 2, 'humidity' => 0], $current->toArray());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideOutOfRangeHumidityCases(): iterable
    {
        yield 'below zero' => [-1];
        yield 'above one hundred' => [101];
    }

    #[DataProvider('provideOutOfRangeHumidityCases')]
    public function testConstructorRejectsAnOutOfRangeHumidity(int $humidityPercentage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Humidity must be between 0 and 100');

        new CurrentWeather(icon: WeatherIcon::Snow, temperature: -3, humidityPercentage: $humidityPercentage);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideBoundaryHumidityCases(): iterable
    {
        yield 'lower bound' => [0];
        yield 'upper bound' => [100];
    }

    #[DataProvider('provideBoundaryHumidityCases')]
    public function testConstructorAcceptsTheHumidityBounds(int $humidityPercentage): void
    {
        $current = new CurrentWeather(icon: WeatherIcon::Cloudy, temperature: 7, humidityPercentage: $humidityPercentage);

        self::assertSame($humidityPercentage, $current->humidityPercentage);
    }
}
