<?php

declare(strict_types=1);

namespace App\Tests\Config\Device;

use App\Config\Sync\ActiveWindowDay;
use App\Tests\Factory\BrightnessScheduleFactory;
use PHPUnit\Framework\TestCase;

final class BrightnessScheduleTest extends TestCase
{
    private const int DEFAULT_LEVEL = 200;

    public function testAnInstantInsideAWindowIsHeldAtTheLevelOfThatWindow(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf(
            [BrightnessScheduleFactory::windowOf('08:00', '22:00', 120)],
            self::DEFAULT_LEVEL,
        );

        self::assertSame(120, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 12:00:00'))->level);
    }

    public function testAnInstantOutsideEveryWindowIsHeldAtTheDefaultLevel(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf(
            [BrightnessScheduleFactory::windowOf('08:00', '22:00', 120)],
            self::DEFAULT_LEVEL,
        );

        self::assertSame(self::DEFAULT_LEVEL, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 23:30:00'))->level);
    }

    public function testAWindowRunningPastMidnightCoversTheSmallHoursOfTheNextDay(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf(
            [BrightnessScheduleFactory::windowOf('22:00', '07:00', 20)],
            self::DEFAULT_LEVEL,
        );

        self::assertSame(20, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 02:00:00'))->level);
        self::assertSame(20, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 23:00:00'))->level);
        self::assertSame(self::DEFAULT_LEVEL, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 12:00:00'))->level);
    }

    public function testTheOpeningBoundIsCoveredAndTheClosingBoundIsNot(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf(
            [BrightnessScheduleFactory::windowOf('08:00', '22:00', 120)],
            self::DEFAULT_LEVEL,
        );

        self::assertSame(120, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 08:00:00'))->level);
        self::assertSame(self::DEFAULT_LEVEL, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 22:00:00'))->level);
    }

    public function testTwoWindowsWrittenBackToBackDisputeNoMinute(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf([
            BrightnessScheduleFactory::windowOf('07:00', '09:00', 60),
            BrightnessScheduleFactory::windowOf('09:00', '22:00', 120),
        ], self::DEFAULT_LEVEL);

        self::assertSame(60, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 08:59:59'))->level);
        self::assertSame(120, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 09:00:00'))->level);
    }

    public function testTwoOverlappingWindowsAreSettledByTheLastOneDeclared(): void
    {
        $broadWindowFirst = BrightnessScheduleFactory::scheduleOf([
            BrightnessScheduleFactory::windowOf('08:00', '22:00', 120),
            BrightnessScheduleFactory::windowOf('12:00', '14:00', 255),
        ], self::DEFAULT_LEVEL);

        $broadWindowLast = BrightnessScheduleFactory::scheduleOf([
            BrightnessScheduleFactory::windowOf('12:00', '14:00', 255),
            BrightnessScheduleFactory::windowOf('08:00', '22:00', 120),
        ], self::DEFAULT_LEVEL);

        $noon = BrightnessScheduleFactory::instantAt('2026-08-05 13:00:00');

        self::assertSame(255, $broadWindowFirst->levelAt($noon)->level);
        self::assertSame(120, $broadWindowLast->levelAt($noon)->level);
    }

    /**
     * The evening window opened the night before is declared last, so it wins even though the other
     * one is anchored on the very day the instant falls in.
     */
    public function testAWindowOpenedTheDayBeforeStillWinsWhenItIsDeclaredLast(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf([
            BrightnessScheduleFactory::windowOf('06:00', '08:00', 90),
            BrightnessScheduleFactory::windowOf('22:00', '07:00', 20),
        ], self::DEFAULT_LEVEL);

        self::assertSame(20, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 06:30:00'))->level);
    }

    public function testADayLeftOutOfTheWindowFallsBackOnTheDefaultLevel(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf(
            [BrightnessScheduleFactory::windowOf('08:00', '22:00', 120, [ActiveWindowDay::Monday, ActiveWindowDay::Tuesday, ActiveWindowDay::Wednesday, ActiveWindowDay::Thursday, ActiveWindowDay::Friday])],
            self::DEFAULT_LEVEL,
        );

        self::assertSame(120, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-05 12:00:00'))->level);
        self::assertSame(self::DEFAULT_LEVEL, $schedule->levelAt(BrightnessScheduleFactory::instantAt('2026-08-08 12:00:00'))->level);
    }

    public function testTheWindowIsReadInTheDeclaredTimezoneAndNotInUtc(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf(
            [BrightnessScheduleFactory::windowOf('00:00', '07:00', 20)],
            self::DEFAULT_LEVEL,
        );

        $halfPastEightInParis = new \DateTimeImmutable('2026-08-05 06:30:00', new \DateTimeZone('UTC'));
        $halfPastFourInParis = new \DateTimeImmutable('2026-08-05 02:30:00', new \DateTimeZone('UTC'));

        self::assertSame(self::DEFAULT_LEVEL, $schedule->levelAt($halfPastEightInParis)->level);
        self::assertSame(20, $schedule->levelAt($halfPastFourInParis)->level);
    }

    public function testTheStringFormNamesTheDefaultLevelTheWindowsAndTheTimezone(): void
    {
        $schedule = BrightnessScheduleFactory::scheduleOf([
            BrightnessScheduleFactory::windowOf('07:00', '22:00', 120),
            BrightnessScheduleFactory::windowOf('22:00', '07:00', 20),
        ], self::DEFAULT_LEVEL);

        self::assertSame('default 200 07:00-22:00@120+22:00-07:00@20 Europe/Paris', (string) $schedule);
    }
}
