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
     * The bounds are written as HH:MM, so a coarser tick would apply the evening level late.
     */
    public const string TICK_INTERVAL = '1 minute';

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
