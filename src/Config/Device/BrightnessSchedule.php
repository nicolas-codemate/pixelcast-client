<?php

declare(strict_types=1);

namespace App\Config\Device;

use App\Client\Settings\BrightnessLevel;

/**
 * The brightness windows read as a planning the client can reason about: which level the panel is
 * meant to hold at a given instant, in the timezone the device is set to.
 */
final readonly class BrightnessSchedule implements \Stringable
{
    /**
     * The declared days repeat every week, so a bound is always found within one.
     */
    private const int DAYS_A_BOUND_IS_FOUND_WITHIN = 7;

    /**
     * @param list<BrightnessWindow> $windows
     */
    private function __construct(
        public array $windows,
        public BrightnessLevel $defaultLevel,
        public \DateTimeZone $timezone,
    ) {
    }

    /**
     * @param list<BrightnessWindow> $windows
     */
    public static function of(array $windows, BrightnessLevel $defaultLevel, \DateTimeZone $timezone): self
    {
        return new self($windows, $defaultLevel, $timezone);
    }

    /**
     * Two windows covering the same instant are settled by the order of the file: the last one
     * declared wins, which is what lets a broad window be written first and trimmed underneath.
     */
    public function levelAt(\DateTimeImmutable $instant): BrightnessLevel
    {
        $localInstant = $instant->setTimezone($this->timezone);
        $selectedLevel = $this->defaultLevel;

        foreach ($this->windows as $window) {
            if ($window->covers($localInstant)) {
                $selectedLevel = $window->level;
            }
        }

        return $selectedLevel;
    }

    /**
     * The next instant a window opens or closes, which is the only kind of instant the level the
     * panel is held at can change at: between two bounds, every instant answers the same level.
     */
    public function nextBoundAfter(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $localInstant = $instant->setTimezone($this->timezone);
        $nextBound = null;

        foreach ($this->windows as $window) {
            foreach ($window->boundsWithinDaysAfter($localInstant, self::DAYS_A_BOUND_IS_FOUND_WITHIN) as $bound) {
                if ($bound <= $localInstant) {
                    continue;
                }

                if (null === $nextBound || $bound < $nextBound) {
                    $nextBound = $bound;
                }
            }
        }

        if (null === $nextBound) {
            throw new \LogicException(\sprintf('No bound of the brightness schedule "%s" found within a week of %s.', $this, $instant->format(\DATE_ATOM)));
        }

        return $nextBound;
    }

    public function __toString(): string
    {
        return \sprintf(
            'default %d %s %s',
            $this->defaultLevel->level,
            implode('+', array_map(strval(...), $this->windows)),
            $this->timezone->getName(),
        );
    }
}
