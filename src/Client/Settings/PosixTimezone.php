<?php

declare(strict_types=1);

namespace App\Client\Settings;

/**
 * Derives the POSIX TZ string the device expects from an IANA timezone, since the device
 * carries no tz database and only reads that format.
 *
 * Two things do not read the way one would expect. A POSIX offset carries the sign opposite
 * to the UTC offset, so UTC+1 is written "-1" and UTC-3 is written "3". And a zone switching
 * more than twice a year, as Africa/Casablanca does, does not fit the two rules POSIX allows:
 * only its first two switches of the year are kept, so its string approximates the real zone.
 *
 * @phpstan-type TimezonePeriod array{ts: int, time: string, offset: int, isdst: bool, abbr: string}
 */
final class PosixTimezone
{
    private const int SECONDS_PER_HOUR = 3600;
    private const int SECONDS_PER_MINUTE = 60;
    private const int DEFAULT_TRANSITION_HOUR = 2;
    private const int STANDARD_TO_DAYLIGHT_SHIFT_IN_SECONDS = 3600;
    private const int LAST_WEEK_OF_MONTH = 5;
    private const int DAYS_PER_WEEK = 7;

    public static function of(\DateTimeZone $timezone, \DateTimeImmutable $referenceInstant): string
    {
        $periods = self::periodsOfTheYearOf($timezone, $referenceInstant);
        $standardPeriod = self::firstPeriodOfKind($periods, isDaylightSaving: false);
        $daylightPeriod = self::firstPeriodOfKind($periods, isDaylightSaving: true);
        $ruleOfTheSwitchToDaylight = self::firstSwitchRuleOfTheYear($periods, isDaylightSaving: true);
        $ruleOfTheSwitchToStandard = self::firstSwitchRuleOfTheYear($periods, isDaylightSaving: false);

        if (null === $standardPeriod) {
            throw new \InvalidArgumentException(\sprintf('The timezone "%s" stays on daylight saving time all year, which POSIX cannot describe.', $timezone->getName()));
        }

        $posixTimezone = self::abbreviation($standardPeriod['abbr']).self::offset($standardPeriod['offset']);

        if (null !== $daylightPeriod && null !== $ruleOfTheSwitchToDaylight && null !== $ruleOfTheSwitchToStandard) {
            $posixTimezone .= self::abbreviation($daylightPeriod['abbr']);

            if ($standardPeriod['offset'] + self::STANDARD_TO_DAYLIGHT_SHIFT_IN_SECONDS !== $daylightPeriod['offset']) {
                $posixTimezone .= self::offset($daylightPeriod['offset']);
            }

            $posixTimezone .= ','.$ruleOfTheSwitchToDaylight.','.$ruleOfTheSwitchToStandard;
        }

        return $posixTimezone;
    }

    /**
     * The entry of index 0 is not a switch: it describes the period in effect on the first of January.
     *
     * @return non-empty-list<TimezonePeriod>
     */
    private static function periodsOfTheYearOf(\DateTimeZone $timezone, \DateTimeImmutable $referenceInstant): array
    {
        $year = (int) $referenceInstant->format('Y');
        $utc = new \DateTimeZone('UTC');
        $firstOfJanuary = new \DateTimeImmutable(\sprintf('%d-01-01 00:00:00', $year), $utc);
        $lastOfDecember = new \DateTimeImmutable(\sprintf('%d-12-31 23:59:59', $year), $utc);

        $periods = $timezone->getTransitions($firstOfJanuary->getTimestamp(), $lastOfDecember->getTimestamp());

        if ([] === $periods) {
            throw new \InvalidArgumentException(\sprintf('The timezone "%s" describes no period in %d.', $timezone->getName(), $year));
        }

        return $periods;
    }

    /**
     * @param non-empty-list<TimezonePeriod> $periods
     *
     * @return TimezonePeriod|null
     */
    private static function firstPeriodOfKind(array $periods, bool $isDaylightSaving): ?array
    {
        foreach ($periods as $period) {
            if ($period['isdst'] === $isDaylightSaving) {
                return $period;
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<TimezonePeriod> $periods
     */
    private static function firstSwitchRuleOfTheYear(array $periods, bool $isDaylightSaving): ?string
    {
        foreach ($periods as $index => $period) {
            if (0 === $index || $period['isdst'] !== $isDaylightSaving) {
                continue;
            }

            return self::switchRule($period['ts'], $periods[$index - 1]['offset']);
        }

        return null;
    }

    private static function switchRule(int $switchTimestamp, int $offsetBeforeTheSwitch): string
    {
        // A rule names the wall clock of the zone, which the offset in effect just before the switch gives.
        $localSwitch = new \DateTimeImmutable('@'.($switchTimestamp + $offsetBeforeTheSwitch));

        $dayOfMonth = (int) $localSwitch->format('j');
        $daysInMonth = (int) $localSwitch->format('t');
        $weekOfMonth = $dayOfMonth + self::DAYS_PER_WEEK > $daysInMonth
            ? self::LAST_WEEK_OF_MONTH
            : intdiv($dayOfMonth - 1, self::DAYS_PER_WEEK) + 1;

        $rule = \sprintf('M%d.%d.%d', (int) $localSwitch->format('n'), $weekOfMonth, (int) $localSwitch->format('w'));
        $hour = (int) $localSwitch->format('G');

        return self::DEFAULT_TRANSITION_HOUR === $hour ? $rule : $rule.'/'.$hour;
    }

    private static function abbreviation(string $abbreviation): string
    {
        return 1 === preg_match('/^[A-Za-z]{3,}$/', $abbreviation) ? $abbreviation : '<'.$abbreviation.'>';
    }

    private static function offset(int $utcOffsetInSeconds): string
    {
        $posixOffsetInSeconds = -$utcOffsetInSeconds;
        $absoluteOffsetInSeconds = abs($posixOffsetInSeconds);

        $hours = intdiv($absoluteOffsetInSeconds, self::SECONDS_PER_HOUR);
        $minutes = intdiv($absoluteOffsetInSeconds % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE);
        $seconds = $absoluteOffsetInSeconds % self::SECONDS_PER_MINUTE;

        $offset = ($posixOffsetInSeconds < 0 ? '-' : '').$hours;

        if (0 !== $minutes || 0 !== $seconds) {
            $offset .= \sprintf(':%02d', $minutes);
        }

        if (0 !== $seconds) {
            $offset .= \sprintf(':%02d', $seconds);
        }

        return $offset;
    }
}
