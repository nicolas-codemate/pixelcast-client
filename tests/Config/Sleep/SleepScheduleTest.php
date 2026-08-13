<?php

declare(strict_types=1);

namespace App\Tests\Config\Sleep;

use App\Config\Sync\ActiveWindowDay;
use App\Tests\Factory\SleepScheduleFactory;
use PHPUnit\Framework\TestCase;

final class SleepScheduleTest extends TestCase
{
    public function testAnInstantInsideTheWindowKnowsWhenThePanelComesBackOn(): void
    {
        $schedule = SleepScheduleFactory::everyNightOf('00:00', '07:00');

        $wakeUp = $schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-05 03:00:00'));

        self::assertNotNull($wakeUp);
        self::assertSame('2026-08-05 07:00:00 Europe/Paris', $wakeUp->format('Y-m-d H:i:s e'));
    }

    public function testTheWakeUpInstantItselfIsAlreadyAwake(): void
    {
        $schedule = SleepScheduleFactory::everyNightOf('00:00', '07:00');

        self::assertNotNull($schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-05 06:59:59')));
        self::assertNull($schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-05 07:00:00')));
    }

    public function testAWindowRunningPastMidnightCoversTheSmallHoursOfTheNextDay(): void
    {
        $schedule = SleepScheduleFactory::everyNightOf('22:00', '07:00');

        $wakeUpAfterMidnight = $schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-05 02:00:00'));
        $wakeUpBeforeMidnight = $schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-05 23:00:00'));

        self::assertNotNull($wakeUpAfterMidnight);
        self::assertSame('2026-08-05 07:00:00 Europe/Paris', $wakeUpAfterMidnight->format('Y-m-d H:i:s e'));
        self::assertNotNull($wakeUpBeforeMidnight);
        self::assertSame('2026-08-06 07:00:00 Europe/Paris', $wakeUpBeforeMidnight->format('Y-m-d H:i:s e'));
    }

    public function testADayLeftOutOfTheScheduleIsNeverAsleep(): void
    {
        $schedule = SleepScheduleFactory::onDaysWithWindows(
            [ActiveWindowDay::Friday, ActiveWindowDay::Saturday],
            [SleepScheduleFactory::windowOf('00:00', '07:00')],
        );

        self::assertNull($schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-05 03:00:00')));
        self::assertNotNull($schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-07 03:00:00')));
    }

    public function testTwoOverlappingWindowsWakeUpAtTheLaterEnd(): void
    {
        $schedule = SleepScheduleFactory::everyNightWithWindows([
            SleepScheduleFactory::windowOf('00:00', '06:00'),
            SleepScheduleFactory::windowOf('00:30', '07:00'),
        ]);

        $wakeUp = $schedule->endOfTheSleepCovering(SleepScheduleFactory::instantAt('2026-08-05 01:00:00'));

        self::assertNotNull($wakeUp);
        self::assertSame('2026-08-05 07:00:00 Europe/Paris', $wakeUp->format('Y-m-d H:i:s e'));
    }

    public function testTheWindowIsReadInTheDeclaredTimezoneAndNotInUtc(): void
    {
        $schedule = SleepScheduleFactory::everyNightOf('00:00', '07:00');

        $halfPastEightInParis = new \DateTimeImmutable('2026-08-05 06:30:00', new \DateTimeZone('UTC'));
        $halfPastFourInParis = new \DateTimeImmutable('2026-08-05 02:30:00', new \DateTimeZone('UTC'));

        self::assertNull($schedule->endOfTheSleepCovering($halfPastEightInParis));
        self::assertNotNull($schedule->endOfTheSleepCovering($halfPastFourInParis));
    }

    public function testAGroupIsWatchedFromTheEndOfTheLastNightOnwards(): void
    {
        $schedule = SleepScheduleFactory::everyNightOf('00:00', '07:00');

        self::assertSame(7200, $schedule->activityAt(SleepScheduleFactory::instantAt('2026-08-05 09:00:00'))->secondsSinceBecameActive);
        self::assertSame(0, $schedule->activityAt(SleepScheduleFactory::instantAt('2026-08-05 07:00:00'))->secondsSinceBecameActive);
    }

    public function testAScheduleThatNeverEndsReportsNoWakeUp(): void
    {
        $schedule = SleepScheduleFactory::onDaysWithWindows([], [SleepScheduleFactory::windowOf('00:00', '07:00')]);

        self::assertNull($schedule->activityAt(SleepScheduleFactory::instantAt('2026-08-05 09:00:00'))->secondsSinceBecameActive);
    }

    public function testAGroupIsInactiveInTheDarkAndActiveFromTheWakeUpOnwards(): void
    {
        $schedule = SleepScheduleFactory::everyNightOf('00:00', '07:00');

        $inTheDark = $schedule->activityAt(SleepScheduleFactory::instantAt('2026-08-05 03:00:00'));
        $justAwake = $schedule->activityAt(SleepScheduleFactory::instantAt('2026-08-05 07:05:00'));

        self::assertFalse($inTheDark->isActive);
        self::assertTrue($justAwake->isActive);
        self::assertSame(300, $justAwake->secondsSinceBecameActive);
    }

    public function testTheStringFormNamesTheDaysTheWindowsAndTheTimezone(): void
    {
        $schedule = SleepScheduleFactory::everyNightWithWindows([
            SleepScheduleFactory::windowOf('22:00', '00:30'),
            SleepScheduleFactory::windowOf('00:30', '07:00'),
        ]);

        self::assertSame('mon,tue,wed,thu,fri,sat,sun 22:00-00:30+00:30-07:00 Europe/Paris', (string) $schedule);
    }
}
