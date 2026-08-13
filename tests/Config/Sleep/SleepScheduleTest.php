<?php

declare(strict_types=1);

namespace App\Tests\Config\Sleep;

use App\Config\Sleep\SleepSchedule;
use App\Config\Sync\ActiveWindowDay;
use App\Tests\Factory\SleepScheduleFactory;
use PHPUnit\Framework\TestCase;

final class SleepScheduleTest extends TestCase
{
    private const string SCHEDULE_TIMEZONE = 'Europe/Paris';

    public function testAnInstantInsideTheWindowKnowsWhenThePanelComesBackOn(): void
    {
        $schedule = self::everyNightOf('00:00', '07:00');

        $wakeUp = $schedule->endOfTheSleepCovering(self::instantAt('2026-08-05 03:00:00'));

        self::assertNotNull($wakeUp);
        self::assertSame('2026-08-05 07:00:00 Europe/Paris', $wakeUp->format('Y-m-d H:i:s e'));
    }

    public function testTheWakeUpInstantItselfIsAlreadyAwake(): void
    {
        $schedule = self::everyNightOf('00:00', '07:00');

        self::assertNotNull($schedule->endOfTheSleepCovering(self::instantAt('2026-08-05 06:59:59')));
        self::assertNull($schedule->endOfTheSleepCovering(self::instantAt('2026-08-05 07:00:00')));
    }

    public function testAWindowRunningPastMidnightCoversTheSmallHoursOfTheNextDay(): void
    {
        $schedule = self::everyNightOf('22:00', '07:00');

        $wakeUpAfterMidnight = $schedule->endOfTheSleepCovering(self::instantAt('2026-08-05 02:00:00'));
        $wakeUpBeforeMidnight = $schedule->endOfTheSleepCovering(self::instantAt('2026-08-05 23:00:00'));

        self::assertNotNull($wakeUpAfterMidnight);
        self::assertSame('2026-08-05 07:00:00 Europe/Paris', $wakeUpAfterMidnight->format('Y-m-d H:i:s e'));
        self::assertNotNull($wakeUpBeforeMidnight);
        self::assertSame('2026-08-06 07:00:00 Europe/Paris', $wakeUpBeforeMidnight->format('Y-m-d H:i:s e'));
    }

    public function testADayLeftOutOfTheScheduleIsNeverAsleep(): void
    {
        $schedule = SleepSchedule::of(
            [ActiveWindowDay::Friday, ActiveWindowDay::Saturday],
            [SleepScheduleFactory::windowOf('00:00', '07:00')],
            new \DateTimeZone(self::SCHEDULE_TIMEZONE),
        );

        self::assertNull($schedule->endOfTheSleepCovering(self::instantAt('2026-08-05 03:00:00')));
        self::assertNotNull($schedule->endOfTheSleepCovering(self::instantAt('2026-08-07 03:00:00')));
    }

    public function testTwoOverlappingWindowsWakeUpAtTheLaterEnd(): void
    {
        $schedule = SleepSchedule::of(
            ActiveWindowDay::cases(),
            [SleepScheduleFactory::windowOf('00:00', '06:00'), SleepScheduleFactory::windowOf('00:30', '07:00')],
            new \DateTimeZone(self::SCHEDULE_TIMEZONE),
        );

        $wakeUp = $schedule->endOfTheSleepCovering(self::instantAt('2026-08-05 01:00:00'));

        self::assertNotNull($wakeUp);
        self::assertSame('2026-08-05 07:00:00 Europe/Paris', $wakeUp->format('Y-m-d H:i:s e'));
    }

    public function testTheWindowIsReadInTheDeclaredTimezoneAndNotInUtc(): void
    {
        $schedule = self::everyNightOf('00:00', '07:00');

        $halfPastEightInParis = new \DateTimeImmutable('2026-08-05 06:30:00', new \DateTimeZone('UTC'));
        $halfPastFourInParis = new \DateTimeImmutable('2026-08-05 02:30:00', new \DateTimeZone('UTC'));

        self::assertNull($schedule->endOfTheSleepCovering($halfPastEightInParis));
        self::assertNotNull($schedule->endOfTheSleepCovering($halfPastFourInParis));
    }

    public function testSecondsSinceTheLastWakeUpCountsFromTheEndOfTheLastNight(): void
    {
        $schedule = self::everyNightOf('00:00', '07:00');

        self::assertSame(7200, $schedule->secondsSinceTheLastWakeUp(self::instantAt('2026-08-05 09:00:00')));
        self::assertSame(0, $schedule->secondsSinceTheLastWakeUp(self::instantAt('2026-08-05 07:00:00')));
    }

    public function testAScheduleThatNeverEndsReportsNoWakeUp(): void
    {
        $schedule = SleepSchedule::of([], [SleepScheduleFactory::windowOf('00:00', '07:00')], new \DateTimeZone(self::SCHEDULE_TIMEZONE));

        self::assertNull($schedule->secondsSinceTheLastWakeUp(self::instantAt('2026-08-05 09:00:00')));
    }

    public function testAGroupIsInactiveInTheDarkAndActiveFromTheWakeUpOnwards(): void
    {
        $schedule = self::everyNightOf('00:00', '07:00');

        $inTheDark = $schedule->activityAt(self::instantAt('2026-08-05 03:00:00'));
        $justAwake = $schedule->activityAt(self::instantAt('2026-08-05 07:05:00'));

        self::assertFalse($inTheDark->isActive);
        self::assertTrue($justAwake->isActive);
        self::assertSame(300, $justAwake->secondsSinceBecameActive);
    }

    public function testTheStringFormNamesTheDaysTheWindowsAndTheTimezone(): void
    {
        $schedule = SleepSchedule::of(
            ActiveWindowDay::cases(),
            [SleepScheduleFactory::windowOf('22:00', '00:30'), SleepScheduleFactory::windowOf('00:30', '07:00')],
            new \DateTimeZone(self::SCHEDULE_TIMEZONE),
        );

        self::assertSame('mon,tue,wed,thu,fri,sat,sun 22:00-00:30+00:30-07:00 Europe/Paris', (string) $schedule);
    }

    private static function everyNightOf(string $fromTimeOfDay, string $toTimeOfDay): SleepSchedule
    {
        return SleepScheduleFactory::everyNightIn(self::SCHEDULE_TIMEZONE, $fromTimeOfDay, $toTimeOfDay);
    }

    private static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant, new \DateTimeZone(self::SCHEDULE_TIMEZONE));
    }
}
