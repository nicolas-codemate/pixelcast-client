<?php

declare(strict_types=1);

namespace App\Tests\Client\Weather;

use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForecastDayTest extends TestCase
{
    public function testToArrayProducesTheDeviceKeys(): void
    {
        $forecastDay = new ForecastDay(
            dayLabel: 'LUN',
            icon: WeatherIcon::HeavyRain,
            minimumTemperature: 4,
            maximumTemperature: 12,
        );

        self::assertSame(
            [
                'day' => 'LUN',
                'icon' => 'w_heavy_rain',
                'temp_min' => 4,
                'temp_max' => 12,
            ],
            $forecastDay->toArray(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidDayLabelCases(): iterable
    {
        yield 'two characters' => ['LU'];
        yield 'four characters' => ['LUND'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideInvalidDayLabelCases')]
    public function testConstructorRejectsADayLabelThatIsNotThreeCharacters(string $dayLabel): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be exactly 3 characters');

        new ForecastDay(dayLabel: $dayLabel, icon: WeatherIcon::Rain, minimumTemperature: 4, maximumTemperature: 12);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideThreeCharacterDayLabelCases(): iterable
    {
        yield 'ascii' => ['MER'];
        yield 'accented' => ['AÔU'];
    }

    #[DataProvider('provideThreeCharacterDayLabelCases')]
    public function testConstructorAcceptsAnyThreeCharacterLabel(string $dayLabel): void
    {
        $forecastDay = new ForecastDay(
            dayLabel: $dayLabel,
            icon: WeatherIcon::ClearDay,
            minimumTemperature: 8,
            maximumTemperature: 21,
        );

        self::assertSame($dayLabel, $forecastDay->dayLabel);
    }
}
