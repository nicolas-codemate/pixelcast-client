<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\WeatherLocale;
use App\Config\WeatherUnits;
use App\Message\SyncMessage;
use App\Message\SyncWeatherMessage;

final readonly class WeatherSyncConfig implements SyncGroupConfig
{
    public function __construct(
        public bool $enabled,
        public SyncInterval $interval,
        public StaleDeclaration $staleDeclaration,
        public ?ActiveWindow $activeWindow,
        public float $latitude,
        public float $longitude,
        public WeatherUnits $units,
        public WeatherLocale $locale,
    ) {
    }

    public static function syncType(): string
    {
        return 'weather';
    }

    public static function fromOptions(array $options): self
    {
        $optionsPath = 'syncs.'.self::syncType();

        $interval = SyncInterval::fromOptions($options, $optionsPath);

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            staleDeclaration: StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::ACCEPTED_OUTSIDE_TRACKER_AND_GAUGE),
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath),
            latitude: SyncOptionReader::requireFloat($options, 'latitude', $optionsPath),
            longitude: SyncOptionReader::requireFloat($options, 'longitude', $optionsPath),
            units: SyncOptionReader::requireEnum($options, 'units', $optionsPath, WeatherUnits::class),
            locale: SyncOptionReader::requireEnum($options, 'locale', $optionsPath, WeatherLocale::class),
        );
    }

    public function syncMessage(): SyncMessage
    {
        return new SyncWeatherMessage();
    }

    public function activityAt(\DateTimeImmutable $instant): SyncGroupActivity
    {
        $activeWindow = $this->activeWindow;

        if (null !== $activeWindow && !$activeWindow->contains($instant)) {
            return SyncGroupActivity::inactive();
        }

        return SyncGroupActivity::activeSince($activeWindow?->secondsSinceOpening($instant));
    }
}
