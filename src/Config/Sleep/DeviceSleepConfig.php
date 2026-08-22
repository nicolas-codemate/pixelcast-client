<?php

declare(strict_types=1);

namespace App\Config\Sleep;

use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepSlot;
use App\Config\Device\DeviceConfig;
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
        public ?\DateTimeZone $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $configTree the whole configuration file, not a sync group
     * @param \DateTimeZone|null $deviceTimezone the timezone of the device, which the schedule reads its hours in unless it declares its own
     */
    public static function optionalFromConfigTree(array $configTree, ?\DateTimeZone $deviceTimezone = null): ?self
    {
        if (!SyncOptionReader::isDeclared($configTree, self::OPTION_KEY)) {
            return null;
        }

        $options = SyncOptionReader::asStringKeyedMap($configTree[self::OPTION_KEY], self::OPTION_KEY);
        $declaredDays = SyncOptionReader::optionalEnumList($options, self::DAYS_OPTION_KEY, self::OPTION_KEY, ActiveWindowDay::class);
        $enabled = SyncOptionReader::requireBool($options, self::ENABLED_OPTION_KEY, self::OPTION_KEY);

        return new self(
            $enabled,
            SyncOptionReader::optionalEnum($options, self::DISPLAY_MODE_OPTION_KEY, self::OPTION_KEY, SleepDisplayMode::cases()) ?? SleepDisplayMode::Black,
            [] === $declaredDays ? ActiveWindowDay::cases() : $declaredDays,
            self::readWindows($options),
            $enabled ? self::readTimezone($options, $deviceTimezone) : null,
        );
    }

    /**
     * The same hours read as a planning the client can reason about, null while the schedule is off:
     * a disabled section leaves the panel on, suspends nothing, and is not even asked which timezone
     * its hours are written in.
     */
    public function sleepSchedule(): ?SleepSchedule
    {
        if (!$this->enabled || null === $this->timezone) {
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
     * @param array<string, mixed> $options
     */
    private static function readTimezone(array $options, ?\DateTimeZone $deviceTimezone): \DateTimeZone
    {
        return SyncOptionReader::optionalTimezone($options, self::TIMEZONE_OPTION_KEY, self::OPTION_KEY)
            ?? $deviceTimezone
            ?? throw PixelCastConfigException::missingKeyOrDeviceDefault(self::OPTION_KEY.'.'.self::TIMEZONE_OPTION_KEY, DeviceConfig::TIMEZONE_PATH, 'the schedule is applied by the device in its own local time, which the container clock does not share.');
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
