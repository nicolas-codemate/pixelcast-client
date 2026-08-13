<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Config\Sleep\SleepSchedule;
use App\Config\Sleep\SleepWindow;
use App\Config\Sync\ActiveWindowDay;

/**
 * The schedules the tests judge instants against, and the instants themselves: every one of them is
 * written in the timezone the schedule is declared in, since a schedule read as UTC covers other
 * hours than the ones the test names.
 */
final class SleepScheduleFactory
{
    public const string SCHEDULE_TIMEZONE = 'Europe/Paris';

    private const string PARENT_PATH = 'sleep.windows[0]';

    public static function everyNightOf(string $fromTimeOfDay, string $toTimeOfDay): SleepSchedule
    {
        return self::everyNightWithWindows([self::windowOf($fromTimeOfDay, $toTimeOfDay)]);
    }

    /**
     * @param list<SleepWindow> $windows
     */
    public static function everyNightWithWindows(array $windows): SleepSchedule
    {
        return self::onDaysWithWindows(ActiveWindowDay::cases(), $windows);
    }

    /**
     * @param list<ActiveWindowDay> $days
     * @param list<SleepWindow> $windows
     */
    public static function onDaysWithWindows(array $days, array $windows): SleepSchedule
    {
        return SleepSchedule::of($days, $windows, new \DateTimeZone(self::SCHEDULE_TIMEZONE));
    }

    public static function windowOf(string $fromTimeOfDay, string $toTimeOfDay): SleepWindow
    {
        return SleepWindow::fromOptions(['from' => $fromTimeOfDay, 'to' => $toTimeOfDay], self::PARENT_PATH);
    }

    public static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant, new \DateTimeZone(self::SCHEDULE_TIMEZONE));
    }

    public static function formatInTheScheduleTimezone(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone(self::SCHEDULE_TIMEZONE))->format('Y-m-d H:i:s');
    }
}
