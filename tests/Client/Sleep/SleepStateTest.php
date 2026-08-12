<?php

declare(strict_types=1);

namespace App\Tests\Client\Sleep;

use App\Client\Sleep\SleepState;
use PHPUnit\Framework\TestCase;

final class SleepStateTest extends TestCase
{
    public function testASleepingDeviceCarriesItsReasonAndTheScheduleItHolds(): void
    {
        $sleepState = SleepState::fromResponseBody([
            'sleeping' => true,
            'reason' => 'schedule',
            'config' => [
                'enabled' => true,
                'display_mode' => 'black',
                'schedule' => [
                    'monday' => ['all_day' => false, 'slots' => [['start' => '20:00', 'end' => '07:00']]],
                    'tuesday' => ['all_day' => false, 'slots' => []],
                ],
                'sleep_until' => 0,
            ],
        ]);

        self::assertTrue($sleepState->sleeping);
        self::assertSame('schedule', $sleepState->reason);
        self::assertTrue($sleepState->scheduleEnabled);
        self::assertSame('black', $sleepState->displayMode);
        self::assertSame(['monday', 'tuesday'], array_keys($sleepState->sleepScheduleByDayName));

        $monday = $sleepState->sleepScheduleByDayName['monday'];
        self::assertFalse($monday->allDay);
        self::assertCount(1, $monday->sleepSlots);
        self::assertSame('20:00', $monday->sleepSlots[0]->start);
        self::assertSame('07:00', $monday->sleepSlots[0]->end);

        self::assertFalse($sleepState->sleepScheduleByDayName['tuesday']->allDay);
        self::assertSame([], $sleepState->sleepScheduleByDayName['tuesday']->sleepSlots);
    }

    public function testAnAwakeDeviceAnswersWithoutAReason(): void
    {
        $sleepState = SleepState::fromResponseBody([
            'sleeping' => false,
            'config' => ['enabled' => true, 'display_mode' => 'black', 'schedule' => [], 'sleep_until' => 0],
        ]);

        self::assertFalse($sleepState->sleeping);
        self::assertNull($sleepState->reason);
        self::assertSame([], $sleepState->sleepScheduleByDayName);
    }

    public function testAManualOverrideIsReportedAsTheReasonOfTheSleep(): void
    {
        $sleepState = SleepState::fromResponseBody([
            'sleeping' => true,
            'reason' => 'override',
            'until' => 4102444800,
            'config' => ['enabled' => true, 'display_mode' => 'clock', 'schedule' => [], 'sleep_until' => 4102444800],
        ]);

        self::assertSame('override', $sleepState->reason);
    }

    public function testADayMarkedAsWholeIsReadAsSuch(): void
    {
        $sleepState = SleepState::fromResponseBody([
            'sleeping' => false,
            'config' => ['schedule' => ['sunday' => ['all_day' => true, 'slots' => [['start' => '20:00', 'end' => '07:00']]]]],
        ]);

        self::assertTrue($sleepState->sleepScheduleByDayName['sunday']->allDay);
    }

    public function testAnEmptyConfigurationStaysReadable(): void
    {
        $sleepState = SleepState::fromResponseBody(['sleeping' => false, 'config' => []]);

        self::assertFalse($sleepState->sleeping);
        self::assertNull($sleepState->scheduleEnabled);
        self::assertNull($sleepState->displayMode);
        self::assertSame([], $sleepState->sleepScheduleByDayName);
    }
}
