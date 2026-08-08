<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Scheduler\ActiveWindowTrigger;
use App\Tests\Factory\ActiveWindowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class ActiveWindowTriggerTest extends TestCase
{
    public function testARunDateInsideTheWindowIsKept(): void
    {
        $trigger = new ActiveWindowTrigger(new PeriodicalTrigger('15 minutes'), ActiveWindowFactory::marketWindowIn('Europe/Paris'));

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-03 10:00:00'));

        self::assertSame('2026-08-03 10:15:00', self::formatInParis($nextRun));
    }

    public function testARunDateOutsideTheWindowIsPushedToTheFirstCycleAfterTheReopening(): void
    {
        $trigger = new ActiveWindowTrigger(new PeriodicalTrigger('15 minutes'), ActiveWindowFactory::marketWindowIn('Europe/Paris'));

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-03 07:00:00'));

        self::assertSame('2026-08-03 09:15:00', self::formatInParis($nextRun));
    }

    public function testAFridayEveningRunDateLandsOnMondayMorning(): void
    {
        $trigger = new ActiveWindowTrigger(new PeriodicalTrigger('15 minutes'), ActiveWindowFactory::marketWindowIn('Europe/Paris'));

        $nextRun = $trigger->getNextRunDate(self::instantAt('2026-08-07 18:00:00'));

        self::assertSame('2026-08-10 09:15:00', self::formatInParis($nextRun));
    }

    public function testACycleThatCanNeverLandInsideTheWindowStopsTheSchedule(): void
    {
        $trigger = new ActiveWindowTrigger(new PeriodicalTrigger('24 hours'), ActiveWindowFactory::marketWindowIn('Europe/Paris'));

        self::assertNull($trigger->getNextRunDate(self::instantAt('2026-08-03 20:00:00')));
    }

    public function testTheStringFormNamesTheInnerTriggerAndTheWindow(): void
    {
        $trigger = new ActiveWindowTrigger(new PeriodicalTrigger('15 minutes'), ActiveWindowFactory::marketWindowIn('Europe/Paris'));

        self::assertSame('every 15 minutes, only mon,tue,wed,thu,fri 09:00-17:45 Europe/Paris', (string) $trigger);
    }

    private static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant, new \DateTimeZone('Europe/Paris'));
    }

    private static function formatInParis(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s');
    }
}
