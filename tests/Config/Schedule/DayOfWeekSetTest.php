<?php

declare(strict_types=1);

namespace App\Tests\Config\Schedule;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Schedule\DayOfWeekSet;
use App\Config\Sync\ActiveWindowDay;
use PHPUnit\Framework\TestCase;

final class DayOfWeekSetTest extends TestCase
{
    private const string PARENT_PATH = 'device.brightnessWindows[0]';
    private const string TIMEZONE = 'Europe/Paris';

    public function testASetCoversTheDaysItNamesAndNoOther(): void
    {
        $days = DayOfWeekSet::of([ActiveWindowDay::Friday, ActiveWindowDay::Saturday]);

        self::assertTrue($days->contains(ActiveWindowDay::Friday));
        self::assertFalse($days->contains(ActiveWindowDay::Sunday));
        self::assertTrue($days->coversTheDayOf(self::instantAt('2026-08-21 10:00:00')));
        self::assertFalse($days->coversTheDayOf(self::instantAt('2026-08-23 10:00:00')));
    }

    public function testEveryDayCoversTheSevenDays(): void
    {
        $days = DayOfWeekSet::everyDay();

        foreach (ActiveWindowDay::cases() as $day) {
            self::assertTrue($days->contains($day));
        }
    }

    public function testDaysLeftOutOfTheOptionsCoverEveryDay(): void
    {
        self::assertEquals(DayOfWeekSet::everyDay(), DayOfWeekSet::fromOptions([], self::PARENT_PATH));
    }

    public function testAnEmptyListOfDaysCoversEveryDay(): void
    {
        self::assertEquals(DayOfWeekSet::everyDay(), DayOfWeekSet::fromOptions(['days' => []], self::PARENT_PATH));
    }

    public function testDeclaredDaysAreReadInTheOrderTheyAreWritten(): void
    {
        $days = DayOfWeekSet::fromOptions(['days' => ['sat', 'mon']], self::PARENT_PATH);

        self::assertEquals(DayOfWeekSet::of([ActiveWindowDay::Saturday, ActiveWindowDay::Monday]), $days);
    }

    public function testAnUnknownDayIsRefusedAndNamesItsPosition(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('device.brightnessWindows[0].days[1]');

        DayOfWeekSet::fromOptions(['days' => ['mon', 'funday']], self::PARENT_PATH);
    }

    public function testTheAnchorDaysRunFromTheOldestToTheDayOfTheInstant(): void
    {
        $days = DayOfWeekSet::everyDay();

        self::assertSame(
            ['2026-08-21', '2026-08-22', '2026-08-23'],
            self::formatAll($days->anchorDaysWithinDaysBefore(self::instantAt('2026-08-23 03:00:00'), 2)),
        );
    }

    public function testTheDaysTheSetLeavesOutAreSkipped(): void
    {
        $days = DayOfWeekSet::of([ActiveWindowDay::Friday]);

        self::assertSame(
            ['2026-08-21'],
            self::formatAll($days->anchorDaysWithinDaysBefore(self::instantAt('2026-08-23 03:00:00'), 2)),
        );
    }

    public function testAnInstantOnADayTheSetLeavesOutStillReachesTheDayBefore(): void
    {
        $days = DayOfWeekSet::of([ActiveWindowDay::Saturday]);

        self::assertSame(
            ['2026-08-22'],
            self::formatAll($days->anchorDaysWithinDaysBefore(self::instantAt('2026-08-23 03:00:00'), 1)),
        );
    }

    public function testTheStringFormListsTheDaysAsTheyAreWrittenInTheFile(): void
    {
        self::assertSame('fri,sat', (string) DayOfWeekSet::of([ActiveWindowDay::Friday, ActiveWindowDay::Saturday]));
        self::assertSame('mon,tue,wed,thu,fri,sat,sun', (string) DayOfWeekSet::everyDay());
    }

    private static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant, new \DateTimeZone(self::TIMEZONE));
    }

    /**
     * @param iterable<\DateTimeImmutable> $anchorDays
     *
     * @return list<string>
     */
    private static function formatAll(iterable $anchorDays): array
    {
        $formatted = [];

        foreach ($anchorDays as $anchorDay) {
            $formatted[] = $anchorDay->format('Y-m-d');
        }

        return $formatted;
    }
}
