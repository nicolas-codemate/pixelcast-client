<?php

declare(strict_types=1);

namespace App\Client\Sleep;

/**
 * One day of the schedule the device holds. A day marked as whole still carries the slots the
 * firmware stored for it, and the flag wins over them.
 */
final readonly class SleepScheduleDay
{
    /**
     * @param list<SleepSlot> $sleepSlots
     */
    public function __construct(
        public bool $allDay,
        public array $sleepSlots,
    ) {
    }
}
