<?php

declare(strict_types=1);

namespace App\Tests\Provider\Weather;

use App\Provider\Weather\WeatherUnits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeatherUnitsTest extends TestCase
{
    public function testValuesListsTheUnitSystemsAcceptedByTheConfiguration(): void
    {
        self::assertSame(['metric', 'imperial'], WeatherUnits::values());
    }

    /**
     * @return iterable<string, array{WeatherUnits, string}>
     */
    public static function provideTemperatureUnitParameterCases(): iterable
    {
        yield 'metric' => [WeatherUnits::Metric, 'celsius'];
        yield 'imperial' => [WeatherUnits::Imperial, 'fahrenheit'];
    }

    #[DataProvider('provideTemperatureUnitParameterCases')]
    public function testTemperatureUnitParameterMatchesTheOpenMeteoQueryValue(WeatherUnits $weatherUnits, string $expectedParameter): void
    {
        self::assertSame($expectedParameter, $weatherUnits->temperatureUnitParameter());
    }
}
