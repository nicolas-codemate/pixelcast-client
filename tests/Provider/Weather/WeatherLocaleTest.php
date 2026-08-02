<?php

declare(strict_types=1);

namespace App\Tests\Provider\Weather;

use App\Provider\Weather\WeatherLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeatherLocaleTest extends TestCase
{
    private const int FORECAST_DAY_LABEL_LENGTH = 3;

    public function testValuesListsTheLocalesAcceptedByTheConfiguration(): void
    {
        self::assertSame(['fr', 'en'], WeatherLocale::values());
    }

    /**
     * @return iterable<string, array{WeatherLocale, string, string}>
     */
    public static function provideDayLabelCases(): iterable
    {
        yield 'french monday' => [WeatherLocale::French, '2026-08-03', 'LUN'];
        yield 'french tuesday' => [WeatherLocale::French, '2026-08-04', 'MAR'];
        yield 'french wednesday' => [WeatherLocale::French, '2026-08-05', 'MER'];
        yield 'french thursday' => [WeatherLocale::French, '2026-08-06', 'JEU'];
        yield 'french friday' => [WeatherLocale::French, '2026-08-07', 'VEN'];
        yield 'french saturday' => [WeatherLocale::French, '2026-08-08', 'SAM'];
        yield 'french sunday' => [WeatherLocale::French, '2026-08-09', 'DIM'];
        yield 'english monday' => [WeatherLocale::English, '2026-08-03', 'MON'];
        yield 'english tuesday' => [WeatherLocale::English, '2026-08-04', 'TUE'];
        yield 'english wednesday' => [WeatherLocale::English, '2026-08-05', 'WED'];
        yield 'english thursday' => [WeatherLocale::English, '2026-08-06', 'THU'];
        yield 'english friday' => [WeatherLocale::English, '2026-08-07', 'FRI'];
        yield 'english saturday' => [WeatherLocale::English, '2026-08-08', 'SAT'];
        yield 'english sunday' => [WeatherLocale::English, '2026-08-09', 'SUN'];
    }

    #[DataProvider('provideDayLabelCases')]
    public function testDayLabelForReturnsTheLocalisedLabel(WeatherLocale $weatherLocale, string $date, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $weatherLocale->dayLabelFor(new \DateTimeImmutable($date)));
    }

    public function testEveryDayLabelIsThreeCharactersLong(): void
    {
        foreach (self::provideDayLabelCases() as [$weatherLocale, $date]) {
            $dayLabel = $weatherLocale->dayLabelFor(new \DateTimeImmutable($date));

            self::assertSame(self::FORECAST_DAY_LABEL_LENGTH, mb_strlen($dayLabel), $dayLabel);
        }
    }
}
