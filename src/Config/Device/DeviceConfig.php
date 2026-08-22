<?php

declare(strict_types=1);

namespace App\Config\Device;

use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\NtpSettings;
use App\Client\Settings\PosixTimezone;
use App\Client\Settings\SettingsPayload;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncOptionReader;

/**
 * The device itself rather than what is shown on it. Its timezone is what the sleep schedule and
 * every active window fall back on, so the file names it once instead of repeating it in each
 * section, and the panel settings that go with it are pushed on demand by app:device:settings.
 */
final readonly class DeviceConfig
{
    public const string OPTION_KEY = 'device';
    public const string TIMEZONE_PATH = self::OPTION_KEY.'.'.self::TIMEZONE_OPTION_KEY;

    private const string TIMEZONE_OPTION_KEY = 'timezone';
    private const string BRIGHTNESS_OPTION_KEY = 'brightness';
    private const string BRIGHTNESS_PATH = self::OPTION_KEY.'.'.self::BRIGHTNESS_OPTION_KEY;
    private const string BRIGHTNESS_WINDOWS_OPTION_KEY = 'brightnessWindows';
    private const string BRIGHTNESS_WINDOWS_PATH = self::OPTION_KEY.'.'.self::BRIGHTNESS_WINDOWS_OPTION_KEY;
    private const string AUTO_ROTATE_OPTION_KEY = 'autoRotate';
    private const string DEFAULT_DURATION_OPTION_KEY = 'defaultDuration';
    private const string WEATHER_DURATION_OPTION_KEY = 'weatherDuration';
    private const string NTP_OPTION_KEY = 'ntp';
    private const string NTP_SERVER_OPTION_KEY = 'server';

    /**
     * An app the rotation never moves away from would freeze the panel, so the shortest duration
     * the file accepts is one millisecond. The device sets no upper bound on it.
     */
    public const int MINIMUM_DEFAULT_DURATION_MILLISECONDS = 1;

    private function __construct(
        public ?\DateTimeZone $timezone,
        public ?BrightnessLevel $brightness,
        public ?BrightnessSchedule $brightnessSchedule,
        public ?bool $autoRotate,
        public ?int $defaultDurationMilliseconds,
        public ?int $weatherDurationMilliseconds,
        public ?string $ntpServer,
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
        $brightnessLevel = SyncOptionReader::optionalInt($options, self::BRIGHTNESS_OPTION_KEY, self::OPTION_KEY, BrightnessLevel::MINIMUM_LEVEL, BrightnessLevel::MAXIMUM_LEVEL);
        $timezone = SyncOptionReader::optionalTimezone($options, self::TIMEZONE_OPTION_KEY, self::OPTION_KEY);
        $brightness = null === $brightnessLevel ? null : BrightnessLevel::create($brightnessLevel);

        return new self(
            timezone: $timezone,
            brightness: $brightness,
            brightnessSchedule: self::readBrightnessSchedule($options, $brightness, $timezone),
            autoRotate: SyncOptionReader::optionalBool($options, self::AUTO_ROTATE_OPTION_KEY, self::OPTION_KEY),
            defaultDurationMilliseconds: SyncOptionReader::optionalInt($options, self::DEFAULT_DURATION_OPTION_KEY, self::OPTION_KEY, self::MINIMUM_DEFAULT_DURATION_MILLISECONDS, \PHP_INT_MAX),
            weatherDurationMilliseconds: SyncOptionReader::optionalInt($options, self::WEATHER_DURATION_OPTION_KEY, self::OPTION_KEY, SettingsPayload::MINIMUM_WEATHER_DURATION_MILLISECONDS, SettingsPayload::MAXIMUM_WEATHER_DURATION_MILLISECONDS),
            ntpServer: self::readNtpServer($options),
        );
    }

    /**
     * @param \DateTimeImmutable $referenceInstant the instant the daylight saving rules of the timezone are read at
     */
    public function toSettingsPayload(\DateTimeImmutable $referenceInstant): SettingsPayload
    {
        try {
            return SettingsPayload::create(
                brightness: $this->brightness,
                autoRotate: $this->autoRotate,
                defaultDurationMilliseconds: $this->defaultDurationMilliseconds,
                weatherDurationMilliseconds: $this->weatherDurationMilliseconds,
                ntp: $this->toNtpSettings($referenceInstant),
            );
        } catch (\InvalidArgumentException $refusedByTheClient) {
            throw PixelCastConfigException::invalidValue(self::OPTION_KEY, $refusedByTheClient->getMessage(), $refusedByTheClient);
        }
    }

    private function toNtpSettings(\DateTimeImmutable $referenceInstant): ?NtpSettings
    {
        if (null === $this->ntpServer && null === $this->timezone) {
            return null;
        }

        try {
            $timezonePosix = null === $this->timezone ? null : PosixTimezone::of($this->timezone, $referenceInstant);

            return NtpSettings::create($this->ntpServer, $timezonePosix);
        } catch (\InvalidArgumentException $refusedByTheClient) {
            throw PixelCastConfigException::invalidValue(self::TIMEZONE_PATH, $refusedByTheClient->getMessage(), $refusedByTheClient);
        }
    }

    /**
     * The declared windows read as a planning the client can reason about, null when the section
     * declares none: the panel then keeps the single level it already holds.
     *
     * The two keys the windows lean on are asked for as the section is read, so a file declaring
     * windows without them stops the consumer at startup rather than at the first tick.
     *
     * @param array<string, mixed> $options
     */
    private static function readBrightnessSchedule(array $options, ?BrightnessLevel $brightness, ?\DateTimeZone $timezone): ?BrightnessSchedule
    {
        $windows = self::readBrightnessWindows($options);

        if ([] === $windows) {
            return null;
        }

        if (null === $brightness) {
            throw PixelCastConfigException::invalidValue(self::BRIGHTNESS_WINDOWS_PATH, \sprintf('declare "%s" as well: it is the level the panel is held at outside every window', self::BRIGHTNESS_PATH));
        }

        if (null === $timezone) {
            throw PixelCastConfigException::invalidValue(self::BRIGHTNESS_WINDOWS_PATH, \sprintf('declare "%s" as well: the bounds are local hours, and the container clock runs on UTC', self::TIMEZONE_PATH));
        }

        return BrightnessSchedule::of($windows, $brightness, $timezone);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<BrightnessWindow>
     */
    private static function readBrightnessWindows(array $options): array
    {
        if (!SyncOptionReader::isDeclared($options, self::BRIGHTNESS_WINDOWS_OPTION_KEY)) {
            return [];
        }

        $windows = [];

        foreach (SyncOptionReader::requireListOfMaps($options, self::BRIGHTNESS_WINDOWS_OPTION_KEY, self::OPTION_KEY) as $index => $windowOptions) {
            $windows[] = BrightnessWindow::fromOptions($windowOptions, \sprintf('%s[%d]', self::BRIGHTNESS_WINDOWS_PATH, $index));
        }

        return $windows;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function readNtpServer(array $options): ?string
    {
        if (!SyncOptionReader::isDeclared($options, self::NTP_OPTION_KEY)) {
            return null;
        }

        $ntpPath = self::OPTION_KEY.'.'.self::NTP_OPTION_KEY;
        $ntpOptions = SyncOptionReader::asStringKeyedMap($options[self::NTP_OPTION_KEY], $ntpPath);

        return SyncOptionReader::requireString($ntpOptions, self::NTP_SERVER_OPTION_KEY, $ntpPath);
    }
}
