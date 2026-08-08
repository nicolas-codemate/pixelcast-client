<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\Exception\PixelCastConfigException;

/**
 * The hours a sync group is allowed to run, declared in the timezone of the market it follows
 * rather than in the timezone of the container. Both bounds are inclusive.
 */
final readonly class ActiveWindow implements \Stringable
{
    private const string OPTION_KEY = 'activeWindow';
    private const string DAYS_OPTION_KEY = 'days';
    private const string FROM_OPTION_KEY = 'from';
    private const string TO_OPTION_KEY = 'to';
    private const string TIMEZONE_OPTION_KEY = 'timezone';
    private const string TIME_OF_DAY_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
    private const int MINUTES_PER_HOUR = 60;
    private const int DAYS_PER_WEEK = 7;

    /**
     * @param list<ActiveWindowDay> $days
     */
    private function __construct(
        public array $days,
        public int $fromMinuteOfDay,
        public int $toMinuteOfDay,
        public \DateTimeZone $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $options the options of the sync group carrying the window
     */
    public static function optionalFromOptions(array $options, string $parentPath): ?self
    {
        if (!SyncOptionReader::isDeclared($options, self::OPTION_KEY)) {
            return null;
        }

        $windowPath = $parentPath.'.'.self::OPTION_KEY;
        $windowOptions = SyncOptionReader::asStringKeyedMap($options[self::OPTION_KEY], $windowPath);

        $fromTime = SyncOptionReader::requireString($windowOptions, self::FROM_OPTION_KEY, $windowPath);
        $toTime = SyncOptionReader::requireString($windowOptions, self::TO_OPTION_KEY, $windowPath);
        $fromMinuteOfDay = self::minuteOfDayOfTime($fromTime, $windowPath.'.'.self::FROM_OPTION_KEY);
        $toMinuteOfDay = self::minuteOfDayOfTime($toTime, $windowPath.'.'.self::TO_OPTION_KEY);

        if ($fromMinuteOfDay >= $toMinuteOfDay) {
            throw PixelCastConfigException::invalidValue($windowPath.'.'.self::TO_OPTION_KEY, \sprintf('expected a time after "%s": a window spanning midnight is not supported, declare the window in the timezone of the market, where it does not cross midnight', $fromTime));
        }

        return new self(
            self::readDays($windowOptions, $windowPath),
            $fromMinuteOfDay,
            $toMinuteOfDay,
            self::readTimezone($windowOptions, $windowPath),
        );
    }

    public function contains(\DateTimeImmutable $instant): bool
    {
        $localInstant = $instant->setTimezone($this->timezone);

        if (!$this->coversTheDayOf($localInstant)) {
            return false;
        }

        $minuteOfDay = self::minuteOfDayOfInstant($localInstant);

        return $minuteOfDay >= $this->fromMinuteOfDay && $minuteOfDay <= $this->toMinuteOfDay;
    }

    public function nextOpeningAfter(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $localInstant = $instant->setTimezone($this->timezone);

        // The declared days repeat every week, so one of the eight days ahead always opens.
        for ($dayOffset = 0; $dayOffset <= self::DAYS_PER_WEEK; ++$dayOffset) {
            $localDay = $localInstant->modify(\sprintf('+%d days', $dayOffset));

            if (!$this->coversTheDayOf($localDay)) {
                continue;
            }

            $opening = $this->openingOfTheDayOf($localDay);
            if ($opening > $localInstant) {
                return $opening;
            }
        }

        throw new \LogicException(\sprintf('No opening of the window "%s" found within a week of %s.', $this, $instant->format(\DATE_ATOM)));
    }

    /**
     * How long the window has been open on the day of the given instant, zero before its opening.
     */
    public function secondsSinceOpening(\DateTimeImmutable $instant): int
    {
        $localInstant = $instant->setTimezone($this->timezone);
        $opening = $this->openingOfTheDayOf($localInstant);

        return max(0, $localInstant->getTimestamp() - $opening->getTimestamp());
    }

    public function __toString(): string
    {
        return \sprintf(
            '%s %s-%s %s',
            implode(',', array_column($this->days, 'value')),
            $this->timeOfDay($this->fromMinuteOfDay),
            $this->timeOfDay($this->toMinuteOfDay),
            $this->timezone->getName(),
        );
    }

    /**
     * @param array<string, mixed> $windowOptions
     *
     * @return list<ActiveWindowDay>
     */
    private static function readDays(array $windowOptions, string $windowPath): array
    {
        $daysPath = $windowPath.'.'.self::DAYS_OPTION_KEY;

        if (!SyncOptionReader::isDeclared($windowOptions, self::DAYS_OPTION_KEY)) {
            return ActiveWindowDay::cases();
        }

        $declaredDays = $windowOptions[self::DAYS_OPTION_KEY];
        if (!\is_array($declaredDays) || !array_is_list($declaredDays)) {
            throw PixelCastConfigException::invalidValue($daysPath, 'expected a list');
        }

        if ([] === $declaredDays) {
            throw PixelCastConfigException::invalidValue($daysPath, 'must not be empty');
        }

        $days = [];
        foreach ($declaredDays as $index => $declaredDay) {
            $day = \is_string($declaredDay) ? ActiveWindowDay::tryFrom($declaredDay) : null;

            if (null === $day) {
                throw PixelCastConfigException::invalidValue(\sprintf('%s[%d]', $daysPath, $index), \sprintf('expected one of: %s', implode(', ', array_column(ActiveWindowDay::cases(), 'value'))));
            }

            $days[] = $day;
        }

        return $days;
    }

    /**
     * @param array<string, mixed> $windowOptions
     */
    private static function readTimezone(array $windowOptions, string $windowPath): \DateTimeZone
    {
        $timezoneName = SyncOptionReader::requireString($windowOptions, self::TIMEZONE_OPTION_KEY, $windowPath);

        try {
            return new \DateTimeZone($timezoneName);
        } catch (\Exception $unknownTimezone) {
            throw PixelCastConfigException::invalidValue($windowPath.'.'.self::TIMEZONE_OPTION_KEY, \sprintf('expected a timezone identifier like "Europe/Paris", got "%s"', $timezoneName), $unknownTimezone);
        }
    }

    private static function minuteOfDayOfTime(string $timeOfDay, string $optionPath): int
    {
        if (1 !== preg_match(self::TIME_OF_DAY_PATTERN, $timeOfDay)) {
            throw PixelCastConfigException::invalidValue($optionPath, \sprintf('expected a time of day like "09:00", got "%s"', $timeOfDay));
        }

        $hours = (int) substr($timeOfDay, 0, 2);
        $minutes = (int) substr($timeOfDay, 3, 2);

        return self::MINUTES_PER_HOUR * $hours + $minutes;
    }

    private static function minuteOfDayOfInstant(\DateTimeImmutable $localInstant): int
    {
        return self::MINUTES_PER_HOUR * (int) $localInstant->format('G') + (int) $localInstant->format('i');
    }

    private function coversTheDayOf(\DateTimeImmutable $localInstant): bool
    {
        return \in_array(ActiveWindowDay::from(strtolower($localInstant->format('D'))), $this->days, true);
    }

    private function openingOfTheDayOf(\DateTimeImmutable $localDay): \DateTimeImmutable
    {
        return $localDay->setTime(intdiv($this->fromMinuteOfDay, self::MINUTES_PER_HOUR), $this->fromMinuteOfDay % self::MINUTES_PER_HOUR);
    }

    private function timeOfDay(int $minuteOfDay): string
    {
        return \sprintf('%02d:%02d', intdiv($minuteOfDay, self::MINUTES_PER_HOUR), $minuteOfDay % self::MINUTES_PER_HOUR);
    }
}
