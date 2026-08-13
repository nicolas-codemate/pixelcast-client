<?php

declare(strict_types=1);

namespace App\Config\Sleep;

use App\Config\Sync\ActiveWindowDay;
use App\Config\Sync\SyncGroupActivity;

/**
 * The sleep section read as a planning the client can reason about: whether a given instant falls in
 * the dark, and when the panel comes back on. The device applies its schedule in its own local time,
 * which the container clock does not share, hence the declared timezone.
 */
final readonly class SleepSchedule implements \Stringable
{
    private const int DAYS_BEFORE_THE_LAST_WAKE_UP = 8;
    private const int DAYS_BEFORE_A_COVERING_STRETCH = 1;

    /**
     * @param list<ActiveWindowDay> $days
     * @param list<SleepWindow> $windows the same windows on every declared day
     */
    private function __construct(
        public array $days,
        public array $windows,
        public \DateTimeZone $timezone,
    ) {
    }

    /**
     * @param list<ActiveWindowDay> $days
     * @param list<SleepWindow> $windows
     */
    public static function of(array $days, array $windows, \DateTimeZone $timezone): self
    {
        return new self($days, $windows, $timezone);
    }

    /**
     * Two windows covering the same instant wake up at the later of the two ends.
     */
    public function endOfTheSleepCovering(\DateTimeImmutable $instant): ?\DateTimeImmutable
    {
        $localInstant = $instant->setTimezone($this->timezone);
        $latestEnd = null;

        foreach ($this->stretchesAnchoredWithinDaysBefore($localInstant, self::DAYS_BEFORE_A_COVERING_STRETCH) as $stretch) {
            if (!$stretch->covers($localInstant)) {
                continue;
            }

            if (null === $latestEnd || $stretch->end > $latestEnd) {
                $latestEnd = $stretch->end;
            }
        }

        return $latestEnd;
    }

    public function activityAt(\DateTimeImmutable $instant): SyncGroupActivity
    {
        if (null !== $this->endOfTheSleepCovering($instant)) {
            return SyncGroupActivity::inactive();
        }

        return SyncGroupActivity::activeSince($this->secondsSinceTheLastWakeUp($instant));
    }

    public function __toString(): string
    {
        return \sprintf(
            '%s %s %s',
            implode(',', array_column($this->days, 'value')),
            implode('+', array_map(strval(...), $this->windows)),
            $this->timezone->getName(),
        );
    }

    /**
     * How long the panel has been lit again, null when the schedule ends nowhere in the week behind
     * the instant, which reads as "awake since forever".
     */
    private function secondsSinceTheLastWakeUp(\DateTimeImmutable $instant): ?int
    {
        $localInstant = $instant->setTimezone($this->timezone);
        $lastWakeUp = null;

        foreach ($this->stretchesAnchoredWithinDaysBefore($localInstant, self::DAYS_BEFORE_THE_LAST_WAKE_UP) as $stretch) {
            if ($stretch->end > $localInstant) {
                continue;
            }

            if (null === $lastWakeUp || $stretch->end > $lastWakeUp) {
                $lastWakeUp = $stretch->end;
            }
        }

        if (null === $lastWakeUp) {
            return null;
        }

        return $localInstant->getTimestamp() - $lastWakeUp->getTimestamp();
    }

    /**
     * A window running past midnight is anchored on the day it starts, so a stretch covering the
     * small hours of a day was opened the day before: the scan always reaches one day further back
     * than the answer needs.
     *
     * @return list<SleepStretch>
     */
    private function stretchesAnchoredWithinDaysBefore(\DateTimeImmutable $localInstant, int $numberOfDaysBefore): array
    {
        $stretches = [];

        for ($dayOffset = $numberOfDaysBefore; $dayOffset >= 0; --$dayOffset) {
            $anchorDay = $localInstant->modify(\sprintf('-%d days', $dayOffset));

            if (!$this->coversTheDayOf($anchorDay)) {
                continue;
            }

            foreach ($this->windows as $window) {
                $stretches[] = $window->stretchStartingOn($anchorDay);
            }
        }

        return $stretches;
    }

    private function coversTheDayOf(\DateTimeImmutable $localDay): bool
    {
        return \in_array(ActiveWindowDay::ofLocalInstant($localDay), $this->days, true);
    }
}
