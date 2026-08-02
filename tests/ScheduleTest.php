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
        $recurringMessages = self::createSchedule()->getSchedule()->getRecurringMessages();

        self::assertCount(1, $recurringMessages);
        self::assertSame('every 30 minutes', (string) $recurringMessages[0]->getTrigger());
    }

    public function testScheduleAndRegistryShareTheSameDefinition(): void
    {
        $recurringMessages = self::createSchedule()->getSchedule()->getRecurringMessages();
        $expectedRecurringMessage = RecurringMessage::every('30 minutes', new SyncWeatherMessage());

        self::assertSame($expectedRecurringMessage->getId(), $recurringMessages[0]->getId());
    }

    private static function createSchedule(): Schedule
    {
        return new Schedule(new ArrayAdapter());
    }
}
