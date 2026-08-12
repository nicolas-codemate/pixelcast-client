<?php

declare(strict_types=1);

namespace App\Client\Sleep;

/**
 * What GET /sleep says the device is doing right now, plus the schedule it currently holds.
 */
final readonly class SleepState
{
    private const string ALL_DAY_WINDOW = 'all day';

    /**
     * @param array<string, list<string>> $sleepWindowsByDayName windows written as "00:00-07:00", keyed by lowercase day name
     */
    private function __construct(
        public bool $sleeping,
        public ?string $reason,
        public ?int $overrideExpiryEpoch,
        public ?bool $scheduleEnabled,
        public ?string $displayMode,
        public array $sleepWindowsByDayName,
    ) {
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    public static function fromResponseBody(array $decodedBody): self
    {
        $reason = $decodedBody['reason'] ?? null;
        $overrideExpiryEpoch = $decodedBody['until'] ?? null;
        $declaredConfig = $decodedBody['config'] ?? null;
        $config = \is_array($declaredConfig) ? $declaredConfig : [];
        $scheduleEnabled = $config['enabled'] ?? null;
        $displayMode = $config['display_mode'] ?? null;

        return new self(
            sleeping: true === ($decodedBody['sleeping'] ?? null),
            reason: \is_string($reason) ? $reason : null,
            overrideExpiryEpoch: \is_int($overrideExpiryEpoch) ? $overrideExpiryEpoch : null,
            scheduleEnabled: \is_bool($scheduleEnabled) ? $scheduleEnabled : null,
            displayMode: \is_string($displayMode) ? $displayMode : null,
            sleepWindowsByDayName: self::readSleepWindowsByDayName($config['schedule'] ?? null),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private static function readSleepWindowsByDayName(mixed $schedule): array
    {
        if (!\is_array($schedule)) {
            return [];
        }

        $sleepWindowsByDayName = [];

        foreach ($schedule as $dayName => $day) {
            if (!\is_string($dayName) || !\is_array($day)) {
                continue;
            }

            $sleepWindowsByDayName[$dayName] = true === ($day['all_day'] ?? null)
                ? [self::ALL_DAY_WINDOW]
                : self::readSleepWindowsOfDay($day['slots'] ?? null);
        }

        return $sleepWindowsByDayName;
    }

    /**
     * @return list<string>
     */
    private static function readSleepWindowsOfDay(mixed $slots): array
    {
        if (!\is_array($slots)) {
            return [];
        }

        $sleepWindows = [];

        foreach ($slots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }

            $start = $slot['start'] ?? null;
            $end = $slot['end'] ?? null;

            if (\is_string($start) && \is_string($end)) {
                $sleepWindows[] = $start.'-'.$end;
            }
        }

        return $sleepWindows;
    }
}
