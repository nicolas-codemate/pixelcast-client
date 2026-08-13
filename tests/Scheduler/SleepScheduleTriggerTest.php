<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Config\Sleep\SleepSchedule;
use App\Config\Sync\ActiveWindowDay;
use App\Scheduler\SleepScheduleTrigger;
use App\Tests\Factory\SleepScheduleFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class SleepScheduleTriggerTest extends TestCase
{
    private const string SCHEDULE_TIMEZONE = 'Europe/Paris';

    public function testARunDateOutsideTheDarkIsKept(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), self::everyNightOf('00:00', '07:00'));

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-05 10:00:00'));

        self::assertSame('2026-08-05 10:15:00', self::formatInParis($nextRun));
    }

    public function testARunDateInTheDarkMovesToTheInstantThePanelComesBackOn(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), self::everyNightOf('00:00', '07:00'));

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-05 02:00:00'));

        self::assertSame('2026-08-05 07:00:00', self::formatInParis($nextRun));
    }

    public function testAWindowRunningPastMidnightIsWalkedToItsMorningEnd(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), self::everyNightOf('22:00', '07:00'));

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-05 23:00:00'));

        self::assertSame('2026-08-06 07:00:00', self::formatInParis($nextRun));
    }

    public function testTwoTouchingWindowsAreWalkedToTheLastWakeUp(): void
    {
        $trigger = new SleepScheduleTrigger(
            new PeriodicalTrigger('15 minutes'),
            SleepSchedule::of(
                ActiveWindowDay::cases(),
                [SleepScheduleFactory::windowOf('22:00', '00:00'), SleepScheduleFactory::windowOf('00:00', '07:00')],
                new \DateTimeZone(self::SCHEDULE_TIMEZONE),
            ),
        );

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-05 21:50:00'));

        self::assertSame('2026-08-06 07:00:00', self::formatInParis($nextRun));
    }

    public function testANightTheScheduleLeavesOutKeepsItsCycles(): void
    {
        $trigger = new SleepScheduleTrigger(
            new PeriodicalTrigger('15 minutes'),
            SleepSchedule::of(
                [ActiveWindowDay::Friday, ActiveWindowDay::Saturday],
                [SleepScheduleFactory::windowOf('00:00', '07:00')],
                new \DateTimeZone(self::SCHEDULE_TIMEZONE),
            ),
        );

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-05 02:00:00'));

        self::assertSame('2026-08-05 02:15:00', self::formatInParis($nextRun));
    }

    public function testACycleThatCanNeverEscapeTheDarkStopsTheSchedule(): void
    {
        $trigger = new SleepScheduleTrigger(
            new PeriodicalTrigger('15 minutes'),
            SleepSchedule::of(
                ActiveWindowDay::cases(),
                [SleepScheduleFactory::windowOf('00:00', '12:00'), SleepScheduleFactory::windowOf('12:00', '00:00')],
                new \DateTimeZone(self::SCHEDULE_TIMEZONE),
            ),
        );

        self::assertNull($trigger->getNextRunDate(self::instantAt('2026-08-05 10:00:00')));
    }

    public function testTheStringFormNamesTheInnerTriggerAndTheSchedule(): void
    {
        $trigger = new SleepScheduleTrigger(new PeriodicalTrigger('15 minutes'), self::everyNightOf('00:00', '07:00'));

        self::assertSame('every 15 minutes, asleep mon,tue,wed,thu,fri,sat,sun 00:00-07:00 Europe/Paris', (string) $trigger);
    }

    private static function everyNightOf(string $fromTimeOfDay, string $toTimeOfDay): SleepSchedule
    {
        return SleepScheduleFactory::everyNightIn(self::SCHEDULE_TIMEZONE, $fromTimeOfDay, $toTimeOfDay);
    }

    private static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant, new \DateTimeZone(self::SCHEDULE_TIMEZONE));
    }

    private static function formatInParis(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone(self::SCHEDULE_TIMEZONE))->format('Y-m-d H:i:s');
    }
}
