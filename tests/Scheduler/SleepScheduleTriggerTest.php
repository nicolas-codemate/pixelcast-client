<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Config\Sync\ActiveWindowDay;
use App\Scheduler\SleepScheduleTrigger;
use App\Tests\Factory\SleepScheduleFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class SleepScheduleTriggerTest extends TestCase
{
    public function testARunDateOutsideTheDarkIsKept(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), SleepScheduleFactory::everyNightOf('00:00', '07:00'));

        $nextRun = $trigger->getNextRunDate(SleepScheduleFactory::instantAt('2026-08-05 10:00:00'));

        self::assertSame('2026-08-05 10:15:00', SleepScheduleFactory::formatInTheScheduleTimezone($nextRun));
    }

    public function testARunDateInTheDarkMovesToTheInstantThePanelComesBackOn(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), SleepScheduleFactory::everyNightOf('00:00', '07:00'));

        $nextRun = $trigger->getNextRunDate(SleepScheduleFactory::instantAt('2026-08-05 02:00:00'));

        self::assertSame('2026-08-05 07:00:00', SleepScheduleFactory::formatInTheScheduleTimezone($nextRun));
    }

    public function testAWindowRunningPastMidnightIsWalkedToItsMorningEnd(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), SleepScheduleFactory::everyNightOf('22:00', '07:00'));

        $nextRun = $trigger->getNextRunDate(SleepScheduleFactory::instantAt('2026-08-05 23:00:00'));

        self::assertSame('2026-08-06 07:00:00', SleepScheduleFactory::formatInTheScheduleTimezone($nextRun));
    }

    public function testTwoTouchingWindowsAreWalkedToTheLastWakeUp(): void
    {
        $trigger = new SleepScheduleTrigger(
            new PeriodicalTrigger('15 minutes'),
            SleepScheduleFactory::everyNightWithWindows([
                SleepScheduleFactory::windowOf('22:00', '00:00'),
                SleepScheduleFactory::windowOf('00:00', '07:00'),
            ]),
        );

        $nextRun = $trigger->getNextRunDate(SleepScheduleFactory::instantAt('2026-08-05 21:50:00'));

        self::assertSame('2026-08-06 07:00:00', SleepScheduleFactory::formatInTheScheduleTimezone($nextRun));
    }

    public function testANightTheScheduleLeavesOutKeepsItsCycles(): void
    {
        $trigger = new SleepScheduleTrigger(
            new PeriodicalTrigger('15 minutes'),
            SleepScheduleFactory::onDaysWithWindows(
                [ActiveWindowDay::Friday, ActiveWindowDay::Saturday],
                [SleepScheduleFactory::windowOf('00:00', '07:00')],
            ),
        );

        $nextRun = $trigger->getNextRunDate(SleepScheduleFactory::instantAt('2026-08-05 02:00:00'));

        self::assertSame('2026-08-05 02:15:00', SleepScheduleFactory::formatInTheScheduleTimezone($nextRun));
    }

    public function testACycleThatCanNeverEscapeTheDarkStopsTheSchedule(): void
    {
        $trigger = new SleepScheduleTrigger(
            new PeriodicalTrigger('15 minutes'),
            SleepScheduleFactory::everyNightWithWindows([
                SleepScheduleFactory::windowOf('00:00', '12:00'),
                SleepScheduleFactory::windowOf('12:00', '00:00'),
            ]),
        );

        self::assertNull($trigger->getNextRunDate(SleepScheduleFactory::instantAt('2026-08-05 10:00:00')));
    }

    public function testTheStringFormNamesTheInnerTriggerAndTheSchedule(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), SleepScheduleFactory::everyNightOf('00:00', '07:00'));

        self::assertSame('every 15 minutes, asleep mon,tue,wed,thu,fri,sat,sun 00:00-07:00 Europe/Paris', (string) $trigger);
    }
}
