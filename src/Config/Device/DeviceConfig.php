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
    private const string AUTO_ROTATE_OPTION_KEY = 'autoRotate';
    private const string DEFAULT_DURATION_OPTION_KEY = 'defaultDuration';
    private const string WEATHER_DURATION_OPTION_KEY = 'weatherDuration';
    private const string NTP_OPTION_KEY = 'ntp';
    private const string NTP_SERVER_OPTION_KEY = 'server';

    /**
     * An app the rotation never moves away from would freeze the panel, so the shortest duration
     * the file accepts is one millisecond. The device sets no upper bound on it.
     */
    private const int MINIMUM_DEFAULT_DURATION_MILLISECONDS = 1;

    private function __construct(
        public ?\DateTimeZone $timezone,
        public ?BrightnessLevel $brightness,
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

        return new self(
            timezone: SyncOptionReader::optionalTimezone($options, self::TIMEZONE_OPTION_KEY, self::OPTION_KEY),
            brightness: null === $brightnessLevel ? null : BrightnessLevel::create($brightnessLevel),
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
