<?php

declare(strict_types=1);

namespace App\Config\Sleep;

use App\Config\Sync\ActiveWindowDay;
use App\Config\Sync\SyncOptionReader;

/**
 * The hours the device turns its panel off, declared once in pixelcast.yaml and pushed on demand.
 * Unlike a sync group, it changes nothing about what the client fetches: the cycles keep pushing
 * while the panel is off.
 */
final readonly class DeviceSleepConfig
{
    public const string OPTION_KEY = 'sleep';

    private const string ENABLED_OPTION_KEY = 'enabled';
    private const string DISPLAY_MODE_OPTION_KEY = 'displayMode';
    private const string DAYS_OPTION_KEY = 'days';
    private const string WINDOWS_OPTION_KEY = 'windows';

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

        return new self(
            SyncOptionReader::requireBool($options, self::ENABLED_OPTION_KEY, self::OPTION_KEY),
            SyncOptionReader::optionalEnum($options, self::DISPLAY_MODE_OPTION_KEY, self::OPTION_KEY, SleepDisplayMode::cases()) ?? SleepDisplayMode::Black,
            [] === $declaredDays ? ActiveWindowDay::cases() : $declaredDays,
            self::readWindows($options),
        );
    }

    /**
     * The seven days, the ones left out of "days" carrying no window: the firmware leaves untouched
     * any day the payload omits, so a day has to be sent empty for the device to forget its windows.
     *
     * @return array<string, list<SleepWindow>>
     */
    public function sleepWindowsByFirmwareDayName(): array
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
