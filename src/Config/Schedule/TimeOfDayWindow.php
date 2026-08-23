<?php

declare(strict_types=1);

namespace App\Config\Schedule;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncOptionReader;

/**
 * A stretch of the day a section holds something of its own, both bounds written as HH:MM.
 *
 * A "to" earlier than "from" is accepted here and runs past midnight, the opposite of ActiveWindow:
 * the firmware reads a slot ending before it starts as spilling into the next day, so from '22:00'
 * to '07:00' is one night, not twenty-three hours. An active window is meant to be written in the
 * timezone of the market, where it never crosses midnight.
 */
final readonly class TimeOfDayWindow implements \Stringable
{
    private const string FROM_OPTION_KEY = 'from';
    private const string TO_OPTION_KEY = 'to';

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
     * The stretch this window opens on the given day, which ends the morning after when the two
     * times are written the wrong way round.
     *
     * The day is expected in the timezone the window is declared in.
     */
    public function stretchStartingOn(\DateTimeImmutable $localDay): Stretch
    {
        $start = self::atTimeOfDay($localDay, $this->fromTimeOfDay);
        $end = self::atTimeOfDay($localDay, $this->toTimeOfDay);

        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        return Stretch::of($start, $end);
    }

    public function __toString(): string
    {
        return $this->fromTimeOfDay.'-'.$this->toTimeOfDay;
    }

    private static function atTimeOfDay(\DateTimeImmutable $localDay, string $timeOfDay): \DateTimeImmutable
    {
        return $localDay->setTime((int) substr($timeOfDay, 0, 2), (int) substr($timeOfDay, 3, 2));
    }
}
