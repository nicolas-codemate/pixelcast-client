<?php

declare(strict_types=1);

namespace App\Config\Schedule;

use App\Config\Sync\ActiveWindowDay;
use App\Config\Sync\SyncOptionReader;

/**
 * The days of the week a window is declared on.
 */
final readonly class DayOfWeekSet implements \Stringable
{
    private const string DAYS_OPTION_KEY = 'days';

    /**
     * A window running past midnight is anchored on the day it starts, so the stretch covering the
     * small hours of a day was opened the day before: a scan always reaches one day further back
     * than the answer needs.
     */
    private const int DAYS_A_STRETCH_CAN_REACH_BACK = 1;

    /**
     * @param list<ActiveWindowDay> $days
     */
    private function __construct(
        private array $days,
    ) {
    }

    /**
     * @param list<ActiveWindowDay> $days
     */
    public static function of(array $days): self
    {
        return new self($days);
    }

    public static function everyDay(): self
    {
        return new self(ActiveWindowDay::cases());
    }

    /**
     * A section leaving the key out, or writing it empty, runs on every day.
     *
     * @param array<string, mixed> $options the options of the section carrying the days
     */
    public static function fromOptions(array $options, string $parentPath): self
    {
        $declaredDays = SyncOptionReader::optionalEnumList($options, self::DAYS_OPTION_KEY, $parentPath, ActiveWindowDay::class);

        return [] === $declaredDays ? self::everyDay() : self::of($declaredDays);
    }

    public function contains(ActiveWindowDay $day): bool
    {
        return \in_array($day, $this->days, true);
    }

    public function coversTheDayOf(\DateTimeImmutable $localInstant): bool
    {
        return $this->contains(ActiveWindowDay::ofLocalInstant($localInstant));
    }

    /**
     * The days a stretch covering the given instant could have been anchored on, oldest first.
     *
     * @return iterable<\DateTimeImmutable>
     */
    public function anchorDaysPossiblyCovering(\DateTimeImmutable $localInstant): iterable
    {
        return $this->anchorDaysWithinDaysBefore($localInstant, self::DAYS_A_STRETCH_CAN_REACH_BACK);
    }

    /**
     * The days this set covers within the given number of days before the instant, oldest first.
     *
     * @return iterable<\DateTimeImmutable>
     */
    public function anchorDaysWithinDaysBefore(\DateTimeImmutable $localInstant, int $numberOfDaysBefore): iterable
    {
        for ($dayOffset = $numberOfDaysBefore; $dayOffset >= 0; --$dayOffset) {
            $anchorDay = $localInstant->modify(\sprintf('-%d days', $dayOffset));

            if ($this->coversTheDayOf($anchorDay)) {
                yield $anchorDay;
            }
        }
    }

    public function __toString(): string
    {
        return implode(',', array_column($this->days, 'value'));
    }
}
