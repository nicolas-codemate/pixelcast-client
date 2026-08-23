<?php

declare(strict_types=1);

namespace App\Config\Device;

use App\Client\Settings\BrightnessLevel;
use App\Config\Schedule\DayOfWeekSet;
use App\Config\Schedule\TimeOfDayWindow;
use App\Config\Sync\SyncOptionReader;

/**
 * A stretch of the day the panel is held at a level of its own, on the days the window is declared
 * on.
 */
final readonly class BrightnessWindow implements \Stringable
{
    private const string LEVEL_OPTION_KEY = 'level';

    private function __construct(
        private TimeOfDayWindow $timeOfDayWindow,
        public BrightnessLevel $level,
        private DayOfWeekSet $days,
    ) {
    }

    /**
     * @param array<string, mixed> $options the options of the single window entry
     */
    public static function fromOptions(array $options, string $parentPath): self
    {
        $timeOfDayWindow = TimeOfDayWindow::fromOptions($options, $parentPath);
        $declaredLevel = SyncOptionReader::requireInt($options, self::LEVEL_OPTION_KEY, $parentPath, BrightnessLevel::MINIMUM_LEVEL, BrightnessLevel::MAXIMUM_LEVEL);

        return new self(
            $timeOfDayWindow,
            BrightnessLevel::create($declaredLevel),
            DayOfWeekSet::fromOptions($options, $parentPath),
        );
    }

    public function covers(\DateTimeImmutable $localInstant): bool
    {
        foreach ($this->days->anchorDaysPossiblyCovering($localInstant) as $anchorDay) {
            if ($this->timeOfDayWindow->stretchStartingOn($anchorDay)->covers($localInstant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The instants this window opens and closes at, from the day before the given one to the given
     * number of days ahead, in the timezone the window is read in.
     *
     * @return iterable<\DateTimeImmutable>
     */
    public function boundsWithinDaysAfter(\DateTimeImmutable $localInstant, int $numberOfDaysAfter): iterable
    {
        foreach ($this->days->anchorDaysWithinDaysAfter($localInstant, $numberOfDaysAfter) as $anchorDay) {
            $stretch = $this->timeOfDayWindow->stretchStartingOn($anchorDay);

            yield $stretch->start;
            yield $stretch->end;
        }
    }

    public function __toString(): string
    {
        return \sprintf('%s@%d', $this->timeOfDayWindow, $this->level->level);
    }
}
