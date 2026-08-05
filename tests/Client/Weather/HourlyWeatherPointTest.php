<?php

declare(strict_types=1);

namespace App\Tests\Client\Weather;

use App\Client\Weather\HourlyWeatherPoint;
use PHPUnit\Framework\TestCase;

final class HourlyWeatherPointTest extends TestCase
{
    public function testToArrayOmitsThePrecipitationKeysWhenTheyAreUnknown(): void
    {
        $hourlyPoint = new HourlyWeatherPoint(hourOfDay: 7, temperature: 12);

        self::assertSame(['h' => 7, 'temp' => 12], $hourlyPoint->toArray());
    }

    public function testToArrayCarriesEveryMeasurement(): void
    {
        $hourlyPoint = new HourlyWeatherPoint(
            hourOfDay: 18,
            temperature: 21,
            precipitationProbabilityPercentage: 80,
            precipitationInTenthsOfMillimetre: 25,
        );

        self::assertSame(['h' => 18, 'temp' => 21, 'pop' => 80, 'precip' => 25], $hourlyPoint->toArray());
    }

    public function testMeasurementsAreRoundedAndPrecipitationConvertedToTenthsOfMillimetre(): void
    {
        $hourlyPoint = HourlyWeatherPoint::fromMeasurements(9, 17.6, 45, 0.8);

        self::assertSame(9, $hourlyPoint->hourOfDay);
        self::assertSame(18, $hourlyPoint->temperature);
        self::assertSame(45, $hourlyPoint->precipitationProbabilityPercentage);
        self::assertSame(8, $hourlyPoint->precipitationInTenthsOfMillimetre);
    }

    public function testMeasurementsOutsideTheDeviceRangeAreClamped(): void
    {
        $downpour = HourlyWeatherPoint::fromMeasurements(14, 120.0, 140.0, 42.0);
        $deepFreeze = HourlyWeatherPoint::fromMeasurements(3, -128.4, -5.0, -1.0);

        self::assertSame(100, $downpour->temperature);
        self::assertSame(100, $downpour->precipitationProbabilityPercentage);
        self::assertSame(255, $downpour->precipitationInTenthsOfMillimetre);
        self::assertSame(-100, $deepFreeze->temperature);
        self::assertSame(0, $deepFreeze->precipitationProbabilityPercentage);
        self::assertSame(0, $deepFreeze->precipitationInTenthsOfMillimetre);
    }

    public function testUnknownPrecipitationStaysUnknown(): void
    {
        $hourlyPoint = HourlyWeatherPoint::fromMeasurements(11, 19.0, null, null);

        self::assertNull($hourlyPoint->precipitationProbabilityPercentage);
        self::assertNull($hourlyPoint->precipitationInTenthsOfMillimetre);
    }

    public function testAnHourOutsideTheDayIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hour of day must be between 0 and 23, got 24.');

        new HourlyWeatherPoint(hourOfDay: 24, temperature: 20);
    }

    public function testAProbabilityAboveOneHundredIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Precipitation probability must be between 0 and 100, got 101.');

        new HourlyWeatherPoint(hourOfDay: 12, temperature: 20, precipitationProbabilityPercentage: 101);
    }
}
