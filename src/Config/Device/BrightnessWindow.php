<?php

declare(strict_types=1);

namespace App\Config\Device;

use App\Client\Settings\BrightnessLevel;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\ActiveWindowDay;
use App\Config\Sync\SyncOptionReader;

/**
 * A stretch of the day the panel is held at a level of its own, both bounds written as HH:MM.
 *
 * A "to" earlier than "from" is accepted here and runs past midnight, the same grammar as a sleep
 * window: an evening dimming from '22:00' to '07:00' is one night, not twenty-three hours.
 */
final readonly class BrightnessWindow implements \Stringable
{
    private const string FROM_OPTION_KEY = 'from';
    private const string TO_OPTION_KEY = 'to';
    private const string LEVEL_OPTION_KEY = 'level';
    private const string DAYS_OPTION_KEY = 'days';

    /**
     * @param list<ActiveWindowDay> $days
     */
    private function __construct(
        public string $fromTimeOfDay,
        public string $toTimeOfDay,
        public BrightnessLevel $level,
        public array $days,
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

        $declaredLevel = SyncOptionReader::optionalInt($options, self::LEVEL_OPTION_KEY, $parentPath, BrightnessLevel::MINIMUM_LEVEL, BrightnessLevel::MAXIMUM_LEVEL)
            ?? throw PixelCastConfigException::missingKey($parentPath.'.'.self::LEVEL_OPTION_KEY);
        $declaredDays = SyncOptionReader::optionalEnumList($options, self::DAYS_OPTION_KEY, $parentPath, ActiveWindowDay::class);

        return new self(
            $fromTimeOfDay,
            $toTimeOfDay,
            BrightnessLevel::create($declaredLevel),
            [] === $declaredDays ? ActiveWindowDay::cases() : $declaredDays,
        );
    }

    /**
     * The stretch this window opens on the given day, which ends the morning after when the two
     * times are written the wrong way round.
     *
     * The day is expected in the timezone the window is declared in.
     */
    public function stretchStartingOn(\DateTimeImmutable $localDay): BrightnessStretch
    {
        $start = self::atTimeOfDay($localDay, $this->fromTimeOfDay);
        $end = self::atTimeOfDay($localDay, $this->toTimeOfDay);

        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        return BrightnessStretch::of($start, $end, $this->level);
    }

    public function coversTheDayOf(\DateTimeImmutable $localDay): bool
    {
        return \in_array(ActiveWindowDay::ofLocalInstant($localDay), $this->days, true);
    }

    public function __toString(): string
    {
        return \sprintf('%s-%s@%d', $this->fromTimeOfDay, $this->toTimeOfDay, $this->level->level);
    }

    private static function atTimeOfDay(\DateTimeImmutable $localDay, string $timeOfDay): \DateTimeImmutable
    {
        return $localDay->setTime((int) substr($timeOfDay, 0, 2), (int) substr($timeOfDay, 3, 2));
    }
}
