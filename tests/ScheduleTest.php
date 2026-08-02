<?php

declare(strict_types=1);

namespace App\Tests;

use App\Message\SyncWeatherMessage;
use App\Schedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\RecurringMessage;

final class ScheduleTest extends TestCase
{
    public function testWeatherIsTheOnlyRegisteredSyncType(): void
    {
        $syncMessages = self::createSchedule()->syncMessages();

        self::assertSame(['weather'], array_keys($syncMessages));
        self::assertInstanceOf(SyncWeatherMessage::class, $syncMessages['weather']);
    }

    public function testWeatherIsScheduledEveryThirtyMinutes(): void
    {
        $schedule = self::createSchedule();
        $expectedRecurringMessage = RecurringMessage::every('30 minutes', $schedule->syncMessages()['weather']);

        $scheduledIds = self::scheduledIds($schedule);

        self::assertContains($expectedRecurringMessage->getId(), $scheduledIds);
    }

    public function testEveryRegisteredSyncTypeIsAlsoScheduled(): void
    {
        $schedule = self::createSchedule();

        self::assertCount(\count($schedule->syncMessages()), self::scheduledIds($schedule));
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

    private static function createSchedule(): Schedule
    {
        return new Schedule(new ArrayAdapter());
    }
}
