<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

/**
 * The label set into the rule above the chart. It states the stretch of calendar the curve
 * spans, which daily bars cover in fewer points than days: thirty closes reach back about
 * six weeks, not thirty days. An empty label is hidden by the device, which then runs the
 * rule the full width - a better fallback than a wrong period when the dates are unreadable.
 */
final class SparklinePeriodLabel
{
    private const string FORMAT = '%dd';
    // The device caps the label at four characters, so it stops holding a day count past 999.
    private const int MAXIMUM_DAY_COUNT = 999;

    public static function forDayCount(?int $coveredDayCount): string
    {
        if (null === $coveredDayCount || $coveredDayCount < 1 || $coveredDayCount > self::MAXIMUM_DAY_COUNT) {
            return '';
        }

        return \sprintf(self::FORMAT, $coveredDayCount);
    }
}
