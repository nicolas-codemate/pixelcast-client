<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Client\Settings\BrightnessLevel;
use App\Config\Device\BrightnessSchedule;
use App\Config\Device\BrightnessWindow;
use App\Config\Sync\ActiveWindowDay;

/**
 * The schedules the tests judge instants against, and the instants themselves: every one of them is
 * written in the timezone the schedule is declared in, since a schedule read as UTC covers other
 * hours than the ones the test names.
 */
final class BrightnessScheduleFactory
{
    public const string SCHEDULE_TIMEZONE = 'Europe/Paris';

    private const string PARENT_PATH = 'device.brightnessWindows[0]';

    /**
     * @param list<BrightnessWindow> $windows
     */
    public static function scheduleOf(array $windows, int $defaultLevel): BrightnessSchedule
    {
        return BrightnessSchedule::of($windows, BrightnessLevel::create($defaultLevel), new \DateTimeZone(self::SCHEDULE_TIMEZONE));
    }

    /**
     * @param list<ActiveWindowDay> $days
     */
    public static function windowOf(string $fromTimeOfDay, string $toTimeOfDay, int $level, array $days = []): BrightnessWindow
    {
        $options = [
            'from' => $fromTimeOfDay,
            'to' => $toTimeOfDay,
            'level' => $level,
        ];

        if ([] !== $days) {
            $options['days'] = array_column($days, 'value');
        }

        return BrightnessWindow::fromOptions($options, self::PARENT_PATH);
    }

    public static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant, new \DateTimeZone(self::SCHEDULE_TIMEZONE));
    }
}
