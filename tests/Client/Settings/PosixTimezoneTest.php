<?php

declare(strict_types=1);

namespace App\Tests\Client\Settings;

use App\Client\Settings\NtpSettings;
use App\Client\Settings\PosixTimezone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PosixTimezoneTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTimezoneCases(): iterable
    {
        yield 'Paris, the example of the device contract' => ['Europe/Paris', 'CET-1CEST,M3.5.0,M10.5.0/3'];
        yield 'UTC, no daylight saving at all' => ['UTC', 'UTC0'];
        yield 'Tokyo, no daylight saving, east of UTC' => ['Asia/Tokyo', 'JST-9'];
        yield 'New York, west of UTC' => ['America/New_York', 'EST5EDT,M3.2.0,M11.1.0'];
        yield 'London, switching at one in the morning' => ['Europe/London', 'GMT0BST,M3.5.0/1,M10.5.0'];
        yield 'Sydney, southern hemisphere' => ['Australia/Sydney', 'AEST-10AEDT,M10.1.0,M4.1.0/3'];
        yield 'Auckland, southern hemisphere' => ['Pacific/Auckland', 'NZST-12NZDT,M9.5.0,M4.1.0/3'];
        yield 'Kolkata, offset of half an hour' => ['Asia/Kolkata', 'IST-5:30'];
        yield 'Ho Chi Minh, numeric abbreviation' => ['Asia/Ho_Chi_Minh', '<+07>-7'];
        yield 'Sao Paulo, numeric abbreviation west of UTC' => ['America/Sao_Paulo', '<-03>3'];
        yield 'Lord Howe, half an hour of daylight saving' => ['Australia/Lord_Howe', '<+1030>-10:30<+11>-11,M10.1.0,M4.1.0'];
    }

    #[DataProvider('provideTimezoneCases')]
    public function testATimezoneIsDerivedIntoThePosixStringTheDeviceReads(string $timezoneName, string $expectedPosixTimezone): void
    {
        $posixTimezone = PosixTimezone::of(new \DateTimeZone($timezoneName), self::referenceInstantOfTheYear(2026));

        self::assertSame($expectedPosixTimezone, $posixTimezone);
    }

    public function testEveryDerivedTimezoneFitsInWhatTheDeviceAccepts(): void
    {
        foreach (self::provideTimezoneCases() as [$timezoneName]) {
            $posixTimezone = PosixTimezone::of(new \DateTimeZone($timezoneName), self::referenceInstantOfTheYear(2026));

            self::assertLessThanOrEqual(NtpSettings::MAXIMUM_TIMEZONE_LENGTH, mb_strlen($posixTimezone), $timezoneName);
        }
    }

    /**
     * Dublin runs on Irish Standard Time in summer and steps back to GMT in winter, which some
     * tz database releases describe as a negative daylight saving period and others as a plain
     * standard period. Both readings name the same hours, and each yields its own POSIX string,
     * so the derivation is pinned to the pair rather than to one release of the database.
     */
    public function testAZoneSteppingBackInWinterIsDerivedAsOneOfTheTwoFormsTheDatabaseDescribesIt(): void
    {
        $posixTimezone = PosixTimezone::of(new \DateTimeZone('Europe/Dublin'), self::referenceInstantOfTheYear(2026));

        self::assertContains($posixTimezone, ['IST-1GMT0,M10.5.0,M3.5.0/1', 'GMT0IST,M3.5.0/1,M10.5.0']);
    }

    public function testTheRuleOfAZoneDoesNotDependOnTheYearItIsDerivedIn(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertSame('CET-1CEST,M3.5.0,M10.5.0/3', PosixTimezone::of($paris, self::referenceInstantOfTheYear(2024)));
        self::assertSame('CET-1CEST,M3.5.0,M10.5.0/3', PosixTimezone::of($paris, self::referenceInstantOfTheYear(2026)));
        self::assertSame('CET-1CEST,M3.5.0,M10.5.0/3', PosixTimezone::of($paris, self::referenceInstantOfTheYear(2029)));
    }

    private static function referenceInstantOfTheYear(int $year): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('%d-06-15 12:00:00', $year), new \DateTimeZone('UTC'));
    }
}
