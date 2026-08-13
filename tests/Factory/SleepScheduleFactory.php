<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Config\Sleep\SleepSchedule;
use App\Config\Sleep\SleepWindow;
use App\Config\Sync\ActiveWindowDay;

/**
 * The schedule the tests judge instants against: the panel off every night of the week.
 */
final class SleepScheduleFactory
{
    private const string PARENT_PATH = 'sleep.windows[0]';

    public static function everyNightIn(string $timezoneName, string $fromTimeOfDay, string $toTimeOfDay): SleepSchedule
    {
        return SleepSchedule::of(
            ActiveWindowDay::cases(),
            [self::windowOf($fromTimeOfDay, $toTimeOfDay)],
            new \DateTimeZone($timezoneName),
        );
    }

    public static function windowOf(string $fromTimeOfDay, string $toTimeOfDay): SleepWindow
    {
        return SleepWindow::fromOptions(['from' => $fromTimeOfDay, 'to' => $toTimeOfDay], self::PARENT_PATH);
    }
}
