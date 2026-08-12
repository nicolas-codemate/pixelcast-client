<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class SleepState implements ResettableState
{
    public const string REASON_SCHEDULE = 'schedule';
    public const string REASON_OVERRIDE = 'override';

    private const string SCHEDULE_KEY = 'schedule';
    private const string ENABLED_KEY = 'enabled';
    private const string SLEEP_UNTIL_KEY = 'sleep_until';
    private const int MINUTES_PER_HOUR = 60;
    private const string TIME_OF_DAY_PATTERN = '/^(\d{2}):(\d{2})$/';

    private const array DEFAULT_SLEEP = [
        'enabled' => false,
        'display_mode' => 'black',
        'schedule' => [],
        'sleep_until' => 0,
    ];

    /** @var array<string, mixed> */
    private array $sleep = self::DEFAULT_SLEEP;

    /**
     * @param array<string, mixed> $partial
     */
    public function patch(array $partial): void
    {
        $patchedSchedule = $partial[self::SCHEDULE_KEY] ?? null;
        unset($partial[self::SCHEDULE_KEY]);

        $this->sleep = array_replace($this->sleep, $partial);

        if (\is_array($patchedSchedule)) {
            // The device leaves untouched any day the payload omits, so days are merged one by one
            // rather than the sent schedule replacing the stored one.
            $this->sleep[self::SCHEDULE_KEY] = array_replace($this->schedule(), $patchedSchedule);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return $this->sleep;
    }

    public function sleepReasonAt(\DateTimeImmutable $instant): ?string
    {
        if ($this->overrideExpiryEpoch() > $instant->getTimestamp()) {
            return self::REASON_OVERRIDE;
        }

        if (true !== ($this->sleep[self::ENABLED_KEY] ?? null)) {
            return null;
        }

        return $this->scheduleCovers($instant) ? self::REASON_SCHEDULE : null;
    }

    public function overrideExpiryEpoch(): int
    {
        $sleepUntil = $this->sleep[self::SLEEP_UNTIL_KEY] ?? null;

        return \is_int($sleepUntil) ? $sleepUntil : 0;
    }

    public function reset(): void
    {
        $this->sleep = self::DEFAULT_SLEEP;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->sleep;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return ['sleep' => $this->sleep];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $persistedSleep = PersistedStateReader::payload($persistedState['sleep'] ?? null);

        if (null === $persistedSleep) {
            return;
        }

        $this->sleep = array_replace(self::DEFAULT_SLEEP, $persistedSleep);
    }

    public function domainKey(): string
    {
        return 'sleep';
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(): array
    {
        return PersistedStateReader::payload($this->sleep[self::SCHEDULE_KEY] ?? null) ?? [];
    }

    private function scheduleCovers(\DateTimeImmutable $instant): bool
    {
        $dayName = strtolower($instant->format('l'));
        $day = PersistedStateReader::payload($this->schedule()[$dayName] ?? null);

        if (null === $day) {
            return false;
        }

        if (true === ($day['all_day'] ?? null)) {
            return true;
        }

        $slots = $day['slots'] ?? null;

        if (!\is_array($slots)) {
            return false;
        }

        $minuteOfDay = self::MINUTES_PER_HOUR * (int) $instant->format('G') + (int) $instant->format('i');

        foreach ($slots as $slot) {
            if (\is_array($slot) && self::slotCoversMinuteOfDay($slot, $minuteOfDay)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $slot
     */
    private static function slotCoversMinuteOfDay(array $slot, int $minuteOfDay): bool
    {
        $startMinuteOfDay = self::minuteOfDayOfTime($slot['start'] ?? null);
        $endMinuteOfDay = self::minuteOfDayOfTime($slot['end'] ?? null);

        if (null === $startMinuteOfDay || null === $endMinuteOfDay) {
            return false;
        }

        // A slot ending before it starts runs past midnight, so it covers both the end of the day
        // and everything before its end time.
        if ($endMinuteOfDay < $startMinuteOfDay) {
            return $minuteOfDay >= $startMinuteOfDay || $minuteOfDay < $endMinuteOfDay;
        }

        return $minuteOfDay >= $startMinuteOfDay && $minuteOfDay < $endMinuteOfDay;
    }

    private static function minuteOfDayOfTime(mixed $timeOfDay): ?int
    {
        if (!\is_string($timeOfDay) || 1 !== preg_match(self::TIME_OF_DAY_PATTERN, $timeOfDay, $timeParts)) {
            return null;
        }

        return self::MINUTES_PER_HOUR * (int) $timeParts[1] + (int) $timeParts[2];
    }
}
