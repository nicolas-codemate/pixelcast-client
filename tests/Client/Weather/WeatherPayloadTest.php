<?php

declare(strict_types=1);

namespace App\Tests\Client\Weather;

use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\HourlyWeatherPoint;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use PHPUnit\Framework\TestCase;

final class WeatherPayloadTest extends TestCase
{
    public function testToArrayOmitsTheForecastKeyWhenThereIsNoForecastDay(): void
    {
        $payload = new WeatherPayload(new CurrentWeather(icon: WeatherIcon::Rain, temperature: 9));

        self::assertSame(['current' => ['icon' => 'w_rain', 'temp' => 9]], $payload->toArray());
    }

    public function testToArrayNestsEveryForecastDayInOrder(): void
    {
        $payload = new WeatherPayload(
            new CurrentWeather(icon: WeatherIcon::Thunder, temperature: 15, humidityPercentage: 80),
            [
                new ForecastDay(dayLabel: 'LUN', icon: WeatherIcon::Rain, minimumTemperature: 4, maximumTemperature: 12),
                new ForecastDay(dayLabel: 'MAR', icon: WeatherIcon::Snow, minimumTemperature: -2, maximumTemperature: 3),
            ],
        );

        self::assertSame(
            [
                'current' => ['icon' => 'w_thunder', 'temp' => 15, 'humidity' => 80],
                'forecast' => [
                    ['day' => 'LUN', 'icon' => 'w_rain', 'temp_min' => 4, 'temp_max' => 12],
                    ['day' => 'MAR', 'icon' => 'w_snow', 'temp_min' => -2, 'temp_max' => 3],
                ],
            ],
            $payload->toArray(),
        );
    }

    public function testToArrayNestsTheHourlyWindowUnderToday(): void
    {
        $payload = new WeatherPayload(
            new CurrentWeather(icon: WeatherIcon::Rain, temperature: 9),
            [],
            [
                new HourlyWeatherPoint(hourOfDay: 15, temperature: 22, precipitationProbabilityPercentage: 0, precipitationInTenthsOfMillimetre: 0),
                new HourlyWeatherPoint(hourOfDay: 16, temperature: 23),
            ],
        );

        self::assertSame(
            [
                'current' => ['icon' => 'w_rain', 'temp' => 9],
                'today' => [
                    'hours' => [
                        ['h' => 15, 'temp' => 22, 'pop' => 0, 'precip' => 0],
                        ['h' => 16, 'temp' => 23],
                    ],
                ],
            ],
            $payload->toArray(),
        );
    }

    public function testConstructorRejectsAThirteenthHourlyPoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 12 hourly points, got 13');

        new WeatherPayload(
            new CurrentWeather(icon: WeatherIcon::Cloudy, temperature: 11),
            [],
            array_fill(0, 13, new HourlyWeatherPoint(hourOfDay: 12, temperature: 20)),
        );
    }

    public function testConstructorAcceptsSevenForecastDays(): void
    {
        $payload = new WeatherPayload(
            new CurrentWeather(icon: WeatherIcon::Cloudy, temperature: 11),
            self::buildForecastDays(7),
        );

        self::assertCount(7, $payload->forecastDays);
    }

    public function testConstructorRejectsAnEighthForecastDay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 7 forecast days, got 8');

        new WeatherPayload(
            new CurrentWeather(icon: WeatherIcon::Cloudy, temperature: 11),
            self::buildForecastDays(8),
        );
    }

    /**
     * @return list<ForecastDay>
     */
    private static function buildForecastDays(int $dayCount): array
    {
        return array_fill(0, $dayCount, new ForecastDay(
            dayLabel: 'LUN',
            icon: WeatherIcon::ClearDay,
            minimumTemperature: 4,
            maximumTemperature: 12,
        ));
    }
}
