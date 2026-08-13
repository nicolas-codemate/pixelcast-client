<?php

declare(strict_types=1);

namespace App\Config\Sleep;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncOptionReader;

/**
 * A stretch of the day the device keeps its panel off, both bounds written as HH:MM.
 *
 * A "to" earlier than "from" is accepted here and runs past midnight, the opposite of ActiveWindow:
 * the firmware reads a slot ending before it starts as spilling into the next day, whereas an
 * active window is meant to be written in the timezone of the market, where it never crosses midnight.
 */
final readonly class SleepWindow implements \Stringable
{
    public const int MINUTES_PER_DAY = 1440;

    private const string FROM_OPTION_KEY = 'from';
    private const string TO_OPTION_KEY = 'to';
    private const int MINUTES_PER_HOUR = 60;

    private function __construct(
        public string $fromTimeOfDay,
        public string $toTimeOfDay,
    ) {
    }

    /**
     * @param array<string, mixed> $options the options of the single window entry
     */
    public static function fromOptions(array $options, string $parentPath): self
    {
        $fromTimeOfDay = SyncOptionReader::requireTimeOfDay($options, self::FROM_OPTION_KEY, $parentPath);
        $toTimeOfDay = SyncOptionReader::requireTimeOfDay($options, self::TO_OPTION_KEY, $parentPath);

        if ($fromTimeOfDay === $toTimeOfDay) {
            throw PixelCastConfigException::invalidValue($parentPath.'.'.self::TO_OPTION_KEY, \sprintf('expected a time other than "%s": a window opening and closing at the same minute covers nothing', $fromTimeOfDay));
        }

        return new self($fromTimeOfDay, $toTimeOfDay);
    }

    /**
     * The night this window opens on the given day, which ends the morning after when the two times
     * are written the wrong way round: from '22:00' to '07:00' is one night, not twenty-three hours.
     *
     * The day is expected in the timezone the window is declared in.
     */
    public function stretchStartingOn(\DateTimeImmutable $localDay): SleepStretch
    {
        $panelGoesOff = self::atTimeOfDay($localDay, $this->fromTimeOfDay);
        $panelComesBackOn = self::atTimeOfDay($localDay, $this->toTimeOfDay);

        if ($panelComesBackOn <= $panelGoesOff) {
            $panelComesBackOn = $panelComesBackOn->modify('+1 day');
        }

        return SleepStretch::of($panelGoesOff, $panelComesBackOn);
    }

    /**
     * The minutes of a day this window darkens, split in two around midnight when it runs past it:
     * repeated on the day after, such a window also darkens the small hours of the day it opens on.
     *
     * @return list<array{int, int}> each range holds its first darkened minute and the first minute
     *                               lit again after it
     */
    public function darkenedMinuteRangesOfADay(): array
    {
        $fromMinuteOfDay = self::minuteOfDayOfTime($this->fromTimeOfDay);
        $toMinuteOfDay = self::minuteOfDayOfTime($this->toTimeOfDay);

        if ($toMinuteOfDay > $fromMinuteOfDay) {
            return [[$fromMinuteOfDay, $toMinuteOfDay]];
        }

        return [[$fromMinuteOfDay, self::MINUTES_PER_DAY], [0, $toMinuteOfDay]];
    }

    public function __toString(): string
    {
        return $this->fromTimeOfDay.'-'.$this->toTimeOfDay;
    }

    private static function atTimeOfDay(\DateTimeImmutable $localDay, string $timeOfDay): \DateTimeImmutable
    {
        return $localDay->setTime((int) substr($timeOfDay, 0, 2), (int) substr($timeOfDay, 3, 2));
    }

    private static function minuteOfDayOfTime(string $timeOfDay): int
    {
        return self::MINUTES_PER_HOUR * (int) substr($timeOfDay, 0, 2) + (int) substr($timeOfDay, 3, 2);
    }
}
