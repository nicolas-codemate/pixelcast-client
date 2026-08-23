<?php

declare(strict_types=1);

namespace App\Config\Sleep;

use App\Config\Schedule\DayOfWeekSet;
use App\Config\Schedule\Stretch;
use App\Config\Schedule\TimeOfDayWindow;
use App\Config\Sync\SyncGroupActivity;

/**
 * The sleep section read as a planning the client can reason about: whether a given instant falls in
 * the dark, and when the panel comes back on. The device applies its schedule in its own local time,
 * which the container clock does not share, hence the declared timezone.
 */
final readonly class SleepSchedule implements \Stringable
{
    private const int DAYS_BEFORE_THE_LAST_WAKE_UP = 8;

    /**
     * @param list<TimeOfDayWindow> $windows the same windows on every declared day
     */
    private function __construct(
        public DayOfWeekSet $days,
        public array $windows,
        public \DateTimeZone $timezone,
    ) {
    }

    /**
     * @param list<TimeOfDayWindow> $windows
     */
    public static function of(DayOfWeekSet $days, array $windows, \DateTimeZone $timezone): self
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

        foreach ($this->stretchesAnchoredOn($this->days->anchorDaysPossiblyCovering($localInstant)) as $stretch) {
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
            $this->days,
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

        foreach ($this->stretchesAnchoredOn($this->days->anchorDaysWithinDaysBefore($localInstant, self::DAYS_BEFORE_THE_LAST_WAKE_UP)) as $stretch) {
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
     * @param iterable<\DateTimeImmutable> $anchorDays
     *
     * @return iterable<Stretch>
     */
    private function stretchesAnchoredOn(iterable $anchorDays): iterable
    {
        foreach ($anchorDays as $anchorDay) {
            foreach ($this->windows as $window) {
                yield $window->stretchStartingOn($anchorDay);
            }
        }
    }
}
