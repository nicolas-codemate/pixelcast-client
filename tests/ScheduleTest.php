<?php

declare(strict_types=1);

namespace App\Tests;

use App\Message\SyncTrackerMessage;
use App\Message\SyncWeatherMessage;
use App\Schedule;
use App\Scheduler\ActiveWindowTrigger;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\RecurringMessage;

final class ScheduleTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Config/Fixtures';

    public function testOnlyTheEnabledSyncGroupsAreRegistered(): void
    {
        $syncMessages = self::createSchedule('syncs-valid.yaml')->syncMessages();

        self::assertSame(['weather'], array_keys($syncMessages));
        self::assertInstanceOf(SyncWeatherMessage::class, $syncMessages['weather']);
    }

    public function testTheWeatherGroupIsScheduledAtTheIntervalOfTheFile(): void
    {
        $schedule = self::createSchedule('syncs-valid.yaml');
        $expectedRecurringMessage = RecurringMessage::every('30 minutes', $schedule->syncMessages()['weather']);

        $recurringMessages = $schedule->getSchedule()->getRecurringMessages();

        self::assertSame('every 30 minutes', (string) $recurringMessages[0]->getTrigger());
        self::assertContains($expectedRecurringMessage->getId(), self::scheduledIds($schedule));
    }

    public function testEveryRegisteredSyncTypeIsAlsoScheduled(): void
    {
        $schedule = self::createSchedule('syncs-valid.yaml');

        self::assertCount(\count($schedule->syncMessages()), self::scheduledIds($schedule));
    }

    public function testAnEnabledTrackerGroupIsScheduledNextToTheWeatherGroup(): void
    {
        $schedule = self::createSchedule('syncs-trackers-enabled.yaml');

        $syncMessages = $schedule->syncMessages();

        self::assertSame(['weather', 'coingecko'], array_keys($syncMessages));
        self::assertEquals(new SyncTrackerMessage('coingecko'), $syncMessages['coingecko']);

        $scheduledIds = self::scheduledIds($schedule);
        self::assertCount(2, $scheduledIds);
        self::assertSame($scheduledIds, array_unique($scheduledIds));
    }

    public function testTheTwelveDataGroupIsScheduledAtTheIntervalOfTheFile(): void
    {
        $schedule = self::createSchedule('syncs-twelvedata-enabled.yaml');

        $syncMessages = $schedule->syncMessages();

        self::assertSame(['twelvedata'], array_keys($syncMessages));
        self::assertEquals(new SyncTrackerMessage('twelvedata'), $syncMessages['twelvedata']);

        $recurringMessages = $schedule->getSchedule()->getRecurringMessages();

        self::assertSame('every 15 minutes', (string) $recurringMessages[0]->getTrigger());
        self::assertContains(
            RecurringMessage::every('15 minutes', $syncMessages['twelvedata'])->getId(),
            self::scheduledIds($schedule),
        );
    }

    public function testADisabledSyncGroupIsNeitherRegisteredNorScheduled(): void
    {
        $schedule = self::createSchedule('syncs-all-disabled.yaml');

        self::assertSame([], $schedule->syncMessages());
        self::assertSame([], $schedule->getSchedule()->getRecurringMessages());
    }

    public function testAGroupWithoutAnActiveWindowKeepsItsPlainPeriodicalTrigger(): void
    {
        $schedule = self::createSchedule('syncs-active-window.yaml');

        $weatherRecurringMessage = $schedule->getSchedule()->getRecurringMessages()[0];

        self::assertSame('every 30 minutes', (string) $weatherRecurringMessage->getTrigger());
        self::assertSame(
            RecurringMessage::every('30 minutes', $schedule->syncMessages()['weather'])->getId(),
            $weatherRecurringMessage->getId(),
        );
    }

    public function testAGroupWithAnActiveWindowIsScheduledThroughTheWindowTrigger(): void
    {
        $schedule = self::createSchedule('syncs-active-window.yaml');

        $boursoramaTrigger = $schedule->getSchedule()->getRecurringMessages()[1]->getTrigger();

        self::assertInstanceOf(ActiveWindowTrigger::class, $boursoramaTrigger);
        self::assertSame('every 15 minutes, only mon,tue,wed,thu,fri 09:00-17:45 Europe/Paris', (string) $boursoramaTrigger);
    }

    public function testAWindowedGroupComesBackAtTheFirstCycleAfterTheReopening(): void
    {
        $schedule = self::createSchedule('syncs-active-window.yaml');
        $boursoramaTrigger = $schedule->getSchedule()->getRecurringMessages()[1]->getTrigger();

        $fridayEvening = new \DateTimeImmutable('2026-08-07 17:50:00', new \DateTimeZone('Europe/Paris'));
        $nextRun = $boursoramaTrigger->getNextRunDate($fridayEvening);

        self::assertNotNull($nextRun);
        self::assertSame('2026-08-10 09:05:00', $nextRun->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s'));
    }

    /**
     * @return list<string>
     */
    private static function scheduledIds(Schedule $schedule): array
    {
        return array_values(array_map(
            static fn (RecurringMessage $recurringMessage): string => $recurringMessage->getId(),
            $schedule->getSchedule()->getRecurringMessages(),
        ));
    }

    private static function createSchedule(string $fixtureName): Schedule
    {
        return new Schedule(
            new ArrayAdapter(),
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName),
        );
    }
}
