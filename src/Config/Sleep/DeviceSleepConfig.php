<?php

declare(strict_types=1);

namespace App\Config\Sleep;

use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepSlot;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\ActiveWindowDay;
use App\Config\Sync\SyncOptionReader;

/**
 * The hours the device turns its panel off, declared once in pixelcast.yaml and pushed on demand.
 * The same hours drive the scheduler, which suspends every sync group while the panel is off.
 */
final readonly class DeviceSleepConfig
{
    public const string OPTION_KEY = 'sleep';

    private const string ENABLED_OPTION_KEY = 'enabled';
    private const string DISPLAY_MODE_OPTION_KEY = 'displayMode';
    private const string DAYS_OPTION_KEY = 'days';
    private const string WINDOWS_OPTION_KEY = 'windows';
    private const string TIMEZONE_OPTION_KEY = 'timezone';

    private const array FIRMWARE_DAY_NAME_BY_DAY = [
        'mon' => 'monday',
        'tue' => 'tuesday',
        'wed' => 'wednesday',
        'thu' => 'thursday',
        'fri' => 'friday',
        'sat' => 'saturday',
        'sun' => 'sunday',
    ];

    /**
     * @param list<ActiveWindowDay> $days
     * @param list<SleepWindow> $windows
     */
    private function __construct(
        public bool $enabled,
        public SleepDisplayMode $displayMode,
        public array $days,
        public array $windows,
        public \DateTimeZone $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $configTree the whole configuration file, not a sync group
     */
    public static function optionalFromConfigTree(array $configTree): ?self
    {
        if (!SyncOptionReader::isDeclared($configTree, self::OPTION_KEY)) {
            return null;
        }

        $options = SyncOptionReader::asStringKeyedMap($configTree[self::OPTION_KEY], self::OPTION_KEY);
        $declaredDays = SyncOptionReader::optionalEnumList($options, self::DAYS_OPTION_KEY, self::OPTION_KEY, ActiveWindowDay::class);
        $days = [] === $declaredDays ? ActiveWindowDay::cases() : $declaredDays;
        $windows = self::readWindows($options);

        self::refuseWindowsLeavingNoWakingMinute($days, $windows);

        return new self(
            SyncOptionReader::requireBool($options, self::ENABLED_OPTION_KEY, self::OPTION_KEY),
            SyncOptionReader::optionalEnum($options, self::DISPLAY_MODE_OPTION_KEY, self::OPTION_KEY, SleepDisplayMode::cases()) ?? SleepDisplayMode::Black,
            $days,
            $windows,
            SyncOptionReader::requireTimezone($options, self::TIMEZONE_OPTION_KEY, self::OPTION_KEY),
        );
    }

    /**
     * The same hours read as a planning the client can reason about, null while the schedule is off:
     * a disabled section leaves the panel on and suspends nothing.
     */
    public function sleepSchedule(): ?SleepSchedule
    {
        if (!$this->enabled) {
            return null;
        }

        return SleepSchedule::of($this->days, $this->windows, $this->timezone);
    }

    public function toSleepPayload(): SleepPayload
    {
        $sleepSlotsByDayName = [];

        foreach ($this->sleepWindowsByFirmwareDayName() as $firmwareDayName => $windows) {
            $sleepSlotsByDayName[$firmwareDayName] = array_map(
                static fn (SleepWindow $window): SleepSlot => new SleepSlot($window->fromTimeOfDay, $window->toTimeOfDay),
                $windows,
            );
        }

        return new SleepPayload($this->enabled, $this->displayMode->value, $sleepSlotsByDayName);
    }

    /**
     * The seven days, the ones left out of "days" carrying no window: the firmware leaves untouched
     * any day the payload omits, so a day has to be sent empty for the device to forget its windows.
     *
     * @return array<string, list<SleepWindow>>
     */
    private function sleepWindowsByFirmwareDayName(): array
    {
        $windowsByFirmwareDayName = [];

        foreach (ActiveWindowDay::cases() as $day) {
            $firmwareDayName = self::FIRMWARE_DAY_NAME_BY_DAY[$day->value];
            $windowsByFirmwareDayName[$firmwareDayName] = \in_array($day, $this->days, true) ? $this->windows : [];
        }

        return $windowsByFirmwareDayName;
    }

    /**
     * Windows tiling the whole day on each of the seven days would never let the panel come back on:
     * the scheduler moves every run date to the next wake-up, finds none, and the cycles stop for
     * good on a screen frozen on its last payload. Left out of the seven days, a day carries no
     * window at all and always ends the darkness, however the windows are written.
     *
     * @param list<ActiveWindowDay> $days
     * @param list<SleepWindow> $windows
     */
    private static function refuseWindowsLeavingNoWakingMinute(array $days, array $windows): void
    {
        if (\count($days) < \count(ActiveWindowDay::cases())) {
            return;
        }

        $darkenedMinuteRanges = [];
        foreach ($windows as $window) {
            foreach ($window->darkenedMinuteRangesOfADay() as $darkenedMinuteRange) {
                $darkenedMinuteRanges[] = $darkenedMinuteRange;
            }
        }

        usort($darkenedMinuteRanges, static fn (array $range, array $otherRange): int => $range[0] <=> $otherRange[0]);

        $firstMinuteNotYetDarkened = 0;
        foreach ($darkenedMinuteRanges as [$firstDarkenedMinute, $firstMinuteLitAgain]) {
            if ($firstDarkenedMinute > $firstMinuteNotYetDarkened) {
                return;
            }

            $firstMinuteNotYetDarkened = max($firstMinuteNotYetDarkened, $firstMinuteLitAgain);
        }

        if ($firstMinuteNotYetDarkened < SleepWindow::MINUTES_PER_DAY) {
            return;
        }

        throw PixelCastConfigException::invalidValue(self::OPTION_KEY.'.'.self::WINDOWS_OPTION_KEY, 'expected at least one minute of the day left out of the windows: these ones cover the day whole, so the panel would never come back on and the sync groups would never run again');
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<SleepWindow>
     */
    private static function readWindows(array $options): array
    {
        $windowsPath = self::OPTION_KEY.'.'.self::WINDOWS_OPTION_KEY;
        $windows = [];

        foreach (SyncOptionReader::requireListOfMaps($options, self::WINDOWS_OPTION_KEY, self::OPTION_KEY) as $index => $windowOptions) {
            $windows[] = SleepWindow::fromOptions($windowOptions, \sprintf('%s[%d]', $windowsPath, $index));
        }

        return $windows;
    }
}
