<?php

declare(strict_types=1);

namespace App\Tests\Simulator\State;

use App\Simulator\State\SleepState;
use PHPUnit\Framework\TestCase;

final class SleepStateTest extends TestCase
{
    private const array NIGHT_SCHEDULE = [
        'monday' => ['all_day' => false, 'slots' => [['start' => '00:00', 'end' => '07:00']]],
    ];

    private const array SCHEDULE_PAST_MIDNIGHT = [
        'monday' => ['all_day' => false, 'slots' => [['start' => '22:00', 'end' => '07:00']]],
    ];

    public function testAFreshStateIsDisabledAndBlank(): void
    {
        $sleep = new SleepState();

        self::assertSame([
            'enabled' => false,
            'display_mode' => 'black',
            'schedule' => [],
            'sleep_until' => 0,
        ], $sleep->current());
    }

    public function testPatchReplacesTheFieldsItCarries(): void
    {
        $sleep = new SleepState();

        $sleep->patch(['enabled' => true, 'display_mode' => 'clock']);

        self::assertTrue($sleep->current()['enabled']);
        self::assertSame('clock', $sleep->current()['display_mode']);
    }

    public function testPatchMergesTheScheduleDayByDay(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['schedule' => self::NIGHT_SCHEDULE]);

        $sleep->patch(['schedule' => ['tuesday' => ['all_day' => true]]]);

        self::assertSame([
            'monday' => ['all_day' => false, 'slots' => [['start' => '00:00', 'end' => '07:00']]],
            'tuesday' => ['all_day' => true],
        ], $sleep->current()['schedule']);
    }

    public function testPatchWithoutAScheduleLeavesTheStoredOneIntact(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['schedule' => self::NIGHT_SCHEDULE]);

        $sleep->patch(['enabled' => true]);

        self::assertSame(self::NIGHT_SCHEDULE, $sleep->current()['schedule']);
    }

    public function testASlotOfTheDayPutsTheDeviceToSleep(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['enabled' => true, 'schedule' => self::NIGHT_SCHEDULE]);

        self::assertSame(SleepState::REASON_SCHEDULE, $sleep->sleepReasonAt(self::mondayAt('01:00')));
        self::assertNull($sleep->sleepReasonAt(self::mondayAt('08:00')));
    }

    public function testASlotEndingBeforeItStartsCoversBothSidesOfMidnight(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['enabled' => true, 'schedule' => self::SCHEDULE_PAST_MIDNIGHT]);

        self::assertSame(SleepState::REASON_SCHEDULE, $sleep->sleepReasonAt(self::mondayAt('23:00')));
        self::assertSame(SleepState::REASON_SCHEDULE, $sleep->sleepReasonAt(self::mondayAt('01:00')));
        self::assertNull($sleep->sleepReasonAt(self::mondayAt('08:00')));
    }

    public function testASlotCoversItsStartMinuteButNotItsEndMinute(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['enabled' => true, 'schedule' => self::SCHEDULE_PAST_MIDNIGHT]);

        self::assertSame(SleepState::REASON_SCHEDULE, $sleep->sleepReasonAt(self::mondayAt('22:00')));
        self::assertSame(SleepState::REASON_SCHEDULE, $sleep->sleepReasonAt(self::mondayAt('06:59')));
        self::assertNull($sleep->sleepReasonAt(self::mondayAt('07:00')));
        self::assertNull($sleep->sleepReasonAt(self::mondayAt('21:59')));
    }

    public function testADayCarryingNoSlotLeavesTheDeviceAwake(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['enabled' => true, 'schedule' => self::NIGHT_SCHEDULE]);

        self::assertNull($sleep->sleepReasonAt(new \DateTimeImmutable('2026-08-11 01:00:00')));
    }

    public function testAnAllDayEntryPutsTheDeviceToSleepAtAnyHour(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['enabled' => true, 'schedule' => ['monday' => ['all_day' => true, 'slots' => []]]]);

        self::assertSame(SleepState::REASON_SCHEDULE, $sleep->sleepReasonAt(self::mondayAt('13:00')));
    }

    public function testADisabledScheduleLeavesTheDeviceAwake(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['enabled' => false, 'schedule' => self::NIGHT_SCHEDULE]);

        self::assertNull($sleep->sleepReasonAt(self::mondayAt('01:00')));
    }

    public function testAnOverrideStillInTheFutureOutranksTheSchedule(): void
    {
        $instant = self::mondayAt('13:00');
        $sleep = new SleepState();

        $sleep->patch(['sleep_until' => $instant->getTimestamp() + 3600]);

        self::assertSame(SleepState::REASON_OVERRIDE, $sleep->sleepReasonAt($instant));
        self::assertSame($instant->getTimestamp() + 3600, $sleep->overrideExpiryEpoch());
    }

    public function testAnExpiredOverrideLeavesTheDeviceAwake(): void
    {
        $instant = self::mondayAt('13:00');
        $sleep = new SleepState();

        $sleep->patch(['sleep_until' => $instant->getTimestamp() - 1]);

        self::assertNull($sleep->sleepReasonAt($instant));
    }

    public function testExportedStateIsRestoredIntoAFreshInstance(): void
    {
        $saved = new SleepState();
        $saved->patch(['enabled' => true, 'schedule' => self::NIGHT_SCHEDULE]);

        $restored = new SleepState();
        $restored->restoreFromPersistence($saved->exportForPersistence());

        self::assertSame($saved->snapshot(), $restored->snapshot());
    }

    public function testRestoringACorruptScheduleLeavesTheDeviceAwake(): void
    {
        $sleep = new SleepState();

        $sleep->restoreFromPersistence(['sleep' => ['enabled' => true, 'schedule' => 'not a map']]);

        self::assertNull($sleep->sleepReasonAt(self::mondayAt('01:00')));
    }

    public function testResetRestoresTheDefaults(): void
    {
        $sleep = new SleepState();
        $sleep->patch(['enabled' => true, 'schedule' => self::NIGHT_SCHEDULE]);

        $sleep->reset();

        self::assertSame([], $sleep->current()['schedule']);
        self::assertFalse($sleep->current()['enabled']);
    }

    public function testDomainKeyNamesTheSleepSection(): void
    {
        self::assertSame('sleep', new SleepState()->domainKey());
    }

    private static function mondayAt(string $timeOfDay): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-10 '.$timeOfDay.':00');
    }
}
