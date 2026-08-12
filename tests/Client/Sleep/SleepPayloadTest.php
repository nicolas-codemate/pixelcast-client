<?php

declare(strict_types=1);

namespace App\Tests\Client\Sleep;

use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepSlot;
use PHPUnit\Framework\TestCase;

final class SleepPayloadTest extends TestCase
{
    public function testADayWithoutSlotIsStillSentSoTheDeviceForgetsItsOwn(): void
    {
        $payload = new SleepPayload(true, 'black', [
            'monday' => [new SleepSlot('00:00', '07:00')],
            'tuesday' => [],
        ]);

        self::assertSame([
            'enabled' => true,
            'display_mode' => 'black',
            'schedule' => [
                'monday' => ['all_day' => false, 'slots' => [['start' => '00:00', 'end' => '07:00']]],
                'tuesday' => ['all_day' => false, 'slots' => []],
            ],
        ], $payload->toArray());
    }

    public function testTheManualSleepIsNeverPartOfTheSerializedPayload(): void
    {
        $payload = new SleepPayload(false, 'clock', []);

        self::assertArrayNotHasKey('sleep_until', $payload->toArray());
    }

    public function testTheTwoSlotsOfADayKeepTheOrderTheyWereGivenIn(): void
    {
        $payload = new SleepPayload(true, 'black', [
            'saturday' => [new SleepSlot('01:00', '07:00'), new SleepSlot('14:00', '16:00')],
        ]);

        self::assertSame(
            [['start' => '01:00', 'end' => '07:00'], ['start' => '14:00', 'end' => '16:00']],
            $payload->toArray()['schedule']['saturday']['slots'],
        );
    }
}
