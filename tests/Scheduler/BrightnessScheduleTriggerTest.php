<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Config\Device\BrightnessWindow;
use App\Config\Sync\ActiveWindowDay;
use App\Scheduler\BrightnessScheduleTrigger;
use App\Tests\Factory\BrightnessScheduleFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The instants the brightness is woken up at: the start of the process, then the bounds of the
 * declared windows and nothing in between.
 */
final class BrightnessScheduleTriggerTest extends TestCase
{
    private const int DEFAULT_LEVEL = 200;
    private const string CONSUMER_START = '2026-08-05 12:00:00';

    public function testTheFirstRunIsDueAsSoonAsTheProcessStarts(): void
    {
        $trigger = self::triggerOf(
            [BrightnessScheduleFactory::windowOf('07:00', '22:00', 120)],
            self::CONSUMER_START,
        );

        self::assertSame('2026-08-05 12:00:01', self::formatted($trigger->getNextRunDate(self::instantAt(self::CONSUMER_START))));
    }

    /**
     * The checkpoint a restarted consumer picks up can be days old, and replaying it would date the
     * run before the panel was last left alone.
     */
    public function testTheFirstRunIsDatedFromTheProcessAndNotFromTheCheckpointItPicksUp(): void
    {
        $trigger = self::triggerOf(
            [BrightnessScheduleFactory::windowOf('07:00', '22:00', 120)],
            self::CONSUMER_START,
        );

        self::assertSame('2026-08-05 12:00:01', self::formatted($trigger->getNextRunDate(self::instantAt('2026-08-01 09:30:00'))));
    }

    public function testTheRunsThatFollowLandOnTheBoundsOfTheWindows(): void
    {
        $trigger = self::triggerOf([
            BrightnessScheduleFactory::windowOf('07:00', '22:00', 120),
            BrightnessScheduleFactory::windowOf('22:00', '07:00', 20),
        ], self::CONSUMER_START);
        $trigger->getNextRunDate(self::instantAt(self::CONSUMER_START));

        self::assertSame('2026-08-05 22:00:00', self::formatted($trigger->getNextRunDate(self::instantAt(self::CONSUMER_START))));
        self::assertSame('2026-08-06 07:00:00', self::formatted($trigger->getNextRunDate(self::instantAt('2026-08-05 22:00:00'))));
        self::assertSame('2026-08-06 22:00:00', self::formatted($trigger->getNextRunDate(self::instantAt('2026-08-06 07:00:00'))));
    }

    public function testAWindowRunningPastMidnightClosesTheMorningAfter(): void
    {
        $trigger = self::triggerOf([BrightnessScheduleFactory::windowOf('22:00', '07:00', 20)], self::CONSUMER_START);
        $trigger->getNextRunDate(self::instantAt(self::CONSUMER_START));

        self::assertSame('2026-08-05 22:00:00', self::formatted($trigger->getNextRunDate(self::instantAt(self::CONSUMER_START))));
        self::assertSame('2026-08-06 07:00:00', self::formatted($trigger->getNextRunDate(self::instantAt('2026-08-05 23:00:00'))));
        self::assertSame('2026-08-06 07:00:00', self::formatted($trigger->getNextRunDate(self::instantAt('2026-08-06 03:00:00'))));
    }

    public function testADayLeftOutOfTheWindowIsWaitedForUntilTheWeekAfter(): void
    {
        $trigger = self::triggerOf(
            [BrightnessScheduleFactory::windowOf('08:00', '22:00', 120, [ActiveWindowDay::Friday])],
            self::CONSUMER_START,
        );
        $trigger->getNextRunDate(self::instantAt(self::CONSUMER_START));

        self::assertSame('2026-08-07 08:00:00', self::formatted($trigger->getNextRunDate(self::instantAt(self::CONSUMER_START))));
        self::assertSame('2026-08-14 08:00:00', self::formatted($trigger->getNextRunDate(self::instantAt('2026-08-07 22:00:00'))));
    }

    public function testTheBoundsAreReadInTheDeclaredTimezoneAndNotInUtc(): void
    {
        $trigger = self::triggerOf([BrightnessScheduleFactory::windowOf('07:00', '22:00', 120)], self::CONSUMER_START);
        $trigger->getNextRunDate(self::instantAt(self::CONSUMER_START));

        $noonInUtc = new \DateTimeImmutable('2026-08-05 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-08-05 22:00:00', self::formatted($trigger->getNextRunDate($noonInUtc)));
    }

    public function testADayOfTypicalWindowsIsWorthAHandfulOfRunsAndNotOnePerMinute(): void
    {
        $trigger = self::triggerOf([
            BrightnessScheduleFactory::windowOf('07:00', '22:00', 120),
            BrightnessScheduleFactory::windowOf('22:00', '07:00', 20),
        ], self::CONSUMER_START);

        $endOfTheDay = self::instantAt('2026-08-06 12:00:00');
        $runDate = $trigger->getNextRunDate(self::instantAt(self::CONSUMER_START));
        $runsOfTheDay = 0;

        while (($runDate = $trigger->getNextRunDate($runDate)) < $endOfTheDay) {
            ++$runsOfTheDay;
        }

        self::assertSame(2, $runsOfTheDay, 'the dimming in the evening and the morning level, where a tick every minute made 1440 of them');
    }

    public function testTheStringFormNamesTheScheduleTheBoundsAreReadFrom(): void
    {
        $trigger = self::triggerOf([BrightnessScheduleFactory::windowOf('07:00', '22:00', 120)], self::CONSUMER_START);

        self::assertSame('at every bound of default 200 07:00-22:00@120 Europe/Paris', (string) $trigger);
    }

    /**
     * @param list<BrightnessWindow> $windows
     */
    private static function triggerOf(array $windows, string $startOfTheProcess): BrightnessScheduleTrigger
    {
        return new BrightnessScheduleTrigger(
            BrightnessScheduleFactory::scheduleOf($windows, self::DEFAULT_LEVEL),
            new MockClock($startOfTheProcess, BrightnessScheduleFactory::SCHEDULE_TIMEZONE),
        );
    }

    private static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return BrightnessScheduleFactory::instantAt($rawInstant);
    }

    private static function formatted(?\DateTimeImmutable $runDate): ?string
    {
        return $runDate?->setTimezone(new \DateTimeZone(BrightnessScheduleFactory::SCHEDULE_TIMEZONE))->format('Y-m-d H:i:s');
    }
}
