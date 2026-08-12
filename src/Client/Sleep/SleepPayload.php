<?php

declare(strict_types=1);

namespace App\Client\Sleep;

final readonly class SleepPayload
{
    /**
     * @param array<string, list<SleepSlot>> $sleepSlotsByDayName keyed by lowercase firmware day name
     */
    public function __construct(
        public bool $enabled,
        public string $displayMode,
        public array $sleepSlotsByDayName,
    ) {
    }

    /**
     * @return array{enabled: bool, display_mode: string, schedule: array<string, array{all_day: bool, slots: list<array{start: string, end: string}>}>}
     */
    public function toArray(): array
    {
        $schedule = [];

        foreach ($this->sleepSlotsByDayName as $dayName => $sleepSlots) {
            $schedule[$dayName] = [
                'all_day' => false,
                'slots' => array_map(
                    static fn (SleepSlot $sleepSlot): array => ['start' => $sleepSlot->start, 'end' => $sleepSlot->end],
                    $sleepSlots,
                ),
            ];
        }

        // sleep_until stays out: it holds the manual sleep, which a weekly schedule knows nothing about.
        return [
            'enabled' => $this->enabled,
            'display_mode' => $this->displayMode,
            'schedule' => $schedule,
        ];
    }
}
