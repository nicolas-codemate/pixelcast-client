<?php

declare(strict_types=1);

namespace App\Client\Sleep;

/**
 * What GET /sleep says the device is doing right now, plus the schedule it currently holds.
 */
final readonly class SleepState
{
    /**
     * @param array<string, SleepScheduleDay> $sleepScheduleByDayName keyed by lowercase day name
     */
    private function __construct(
        public bool $sleeping,
        public ?string $reason,
        public ?bool $scheduleEnabled,
        public ?string $displayMode,
        public array $sleepScheduleByDayName,
    ) {
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    public static function fromResponseBody(array $decodedBody): self
    {
        $reason = $decodedBody['reason'] ?? null;
        $declaredConfig = $decodedBody['config'] ?? null;
        $config = \is_array($declaredConfig) ? $declaredConfig : [];
        $scheduleEnabled = $config['enabled'] ?? null;
        $displayMode = $config['display_mode'] ?? null;

        return new self(
            sleeping: true === ($decodedBody['sleeping'] ?? null),
            reason: \is_string($reason) ? $reason : null,
            scheduleEnabled: \is_bool($scheduleEnabled) ? $scheduleEnabled : null,
            displayMode: \is_string($displayMode) ? $displayMode : null,
            sleepScheduleByDayName: self::readSleepScheduleByDayName($config['schedule'] ?? null),
        );
    }

    /**
     * @return array<string, SleepScheduleDay>
     */
    private static function readSleepScheduleByDayName(mixed $schedule): array
    {
        if (!\is_array($schedule)) {
            return [];
        }

        $sleepScheduleByDayName = [];

        foreach ($schedule as $dayName => $day) {
            if (!\is_string($dayName) || !\is_array($day)) {
                continue;
            }

            $sleepScheduleByDayName[$dayName] = new SleepScheduleDay(
                true === ($day['all_day'] ?? null),
                self::readSleepSlotsOfDay($day['slots'] ?? null),
            );
        }

        return $sleepScheduleByDayName;
    }

    /**
     * @return list<SleepSlot>
     */
    private static function readSleepSlotsOfDay(mixed $slots): array
    {
        if (!\is_array($slots)) {
            return [];
        }

        $sleepSlots = [];

        foreach ($slots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }

            $start = $slot['start'] ?? null;
            $end = $slot['end'] ?? null;

            if (\is_string($start) && \is_string($end)) {
                $sleepSlots[] = new SleepSlot($start, $end);
            }
        }

        return $sleepSlots;
    }
}
