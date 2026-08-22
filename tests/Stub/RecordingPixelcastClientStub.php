<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\CustomApp\CustomAppPayload;
use App\Client\Gauge\GaugePayload;
use App\Client\Icon\IconsSnapshot;
use App\Client\Icon\IconUpload;
use App\Client\Indicator\IndicatorPayload;
use App\Client\Notification\NotificationPayload;
use App\Client\PixelcastClientInterface;
use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\SettingsPayload;
use App\Client\Settings\SettingsSnapshot;
use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepState;
use App\Client\Stats\StatsSnapshot;
use App\Client\Tracker\TrackerPayload;
use App\Client\Weather\WeatherPayload;

final class RecordingPixelcastClientStub implements PixelcastClientInterface
{
    /**
     * @var list<WeatherPayload>
     */
    public array $pushedPayloads = [];

    /**
     * @var list<TrackerPayload>
     */
    public array $pushedTrackers = [];

    /**
     * @var list<string>
     */
    public array $deletedTrackerNames = [];

    /**
     * @var list<GaugePayload>
     */
    public array $pushedGauges = [];

    /**
     * @var list<CustomAppPayload>
     */
    public array $pushedCustomApps = [];

    /**
     * @var list<string>
     */
    public array $deletedCustomAppNames = [];

    /**
     * @var list<NotificationPayload>
     */
    public array $pushedNotifications = [];

    /**
     * @var list<SleepPayload>
     */
    public array $pushedSleepPayloads = [];

    /**
     * @var list<SettingsPayload>
     */
    public array $pushedSettingsPayloads = [];

    /**
     * @var list<BrightnessLevel>
     */
    public array $pushedBrightnessLevels = [];

    public ?SleepState $sleepStateToReturn = null;

    public ?SettingsSnapshot $settingsSnapshotToReturn = null;

    public ?StatsSnapshot $statsSnapshotToReturn = null;

    public ?IconsSnapshot $iconsSnapshotToReturn = null;

    public int $listIconsCallCount = 0;

    /**
     * @var list<IconUpload>
     */
    public array $uploadedIcons = [];

    /**
     * @var list<string>
     */
    public array $deletedIconNames = [];

    /**
     * @var list<array{id: int, name: string|null}>
     */
    public array $downloadedLaMetricIcons = [];

    /**
     * @var list<array{slot: int, indicator: IndicatorPayload}>
     */
    public array $setIndicators = [];

    /**
     * @var list<int>
     */
    public array $clearedIndicatorSlots = [];

    public int $dismissedNotificationCount = 0;

    public int $rebootCount = 0;

    /**
     * @param array<string, \Throwable> $trackerFailures keyed by tracker name
     * @param array<int, \Throwable> $laMetricIconFailures keyed by LaMetric icon id
     */
    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly array $trackerFailures = [],
        private readonly ?\Throwable $sleepStateFailure = null,
        private readonly ?\Throwable $settingsSnapshotFailure = null,
        private readonly array $laMetricIconFailures = [],
    ) {
    }

    public function pushWeather(WeatherPayload $weather): void
    {
        $this->failIfConfigured();

        $this->pushedPayloads[] = $weather;
    }

    public function pushTracker(TrackerPayload $tracker): void
    {
        if (isset($this->trackerFailures[$tracker->name])) {
            throw $this->trackerFailures[$tracker->name];
        }

        $this->failIfConfigured();

        $this->pushedTrackers[] = $tracker;
    }

    public function deleteTracker(string $trackerName): void
    {
        $this->failIfConfigured();

        $this->deletedTrackerNames[] = $trackerName;
    }

    public function pushGauge(GaugePayload $gauge): void
    {
        $this->failIfConfigured();

        $this->pushedGauges[] = $gauge;
    }

    public function pushCustomApp(CustomAppPayload $customApp): void
    {
        $this->failIfConfigured();

        $this->pushedCustomApps[] = $customApp;
    }

    public function deleteCustomApp(string $customAppName): void
    {
        $this->failIfConfigured();

        $this->deletedCustomAppNames[] = $customAppName;
    }

    public function pushNotification(NotificationPayload $notification): void
    {
        $this->failIfConfigured();

        $this->pushedNotifications[] = $notification;
    }

    public function dismissNotification(): void
    {
        $this->failIfConfigured();

        ++$this->dismissedNotificationCount;
    }

    public function pushSleepConfiguration(SleepPayload $sleep): void
    {
        $this->failIfConfigured();

        $this->pushedSleepPayloads[] = $sleep;
    }

    public function fetchSleepState(): SleepState
    {
        if (null !== $this->sleepStateFailure) {
            throw $this->sleepStateFailure;
        }

        $this->failIfConfigured();

        return $this->sleepStateToReturn ?? SleepState::fromResponseBody(['sleeping' => false, 'config' => []]);
    }

    public function fetchSettings(): SettingsSnapshot
    {
        if (null !== $this->settingsSnapshotFailure) {
            throw $this->settingsSnapshotFailure;
        }

        $this->failIfConfigured();

        return $this->settingsSnapshotToReturn ?? SettingsSnapshot::fromResponseBody([]);
    }

    public function pushSettings(SettingsPayload $settings): void
    {
        $this->failIfConfigured();

        $this->pushedSettingsPayloads[] = $settings;
    }

    public function pushBrightness(BrightnessLevel $brightness): void
    {
        $this->failIfConfigured();

        $this->pushedBrightnessLevels[] = $brightness;
    }

    public function fetchStats(): StatsSnapshot
    {
        $this->failIfConfigured();

        return $this->statsSnapshotToReturn ?? StatsSnapshot::fromResponseBody([]);
    }

    public function listIcons(): IconsSnapshot
    {
        ++$this->listIconsCallCount;

        $this->failIfConfigured();

        return $this->iconsSnapshotToReturn ?? IconsSnapshot::fromResponseBody([]);
    }

    public function uploadIcon(IconUpload $icon): void
    {
        $this->failIfConfigured();

        $this->uploadedIcons[] = $icon;
    }

    public function deleteIcon(string $iconName): void
    {
        $this->failIfConfigured();

        $this->deletedIconNames[] = $iconName;
    }

    public function downloadLaMetricIcon(int $laMetricIconId, ?string $iconName = null): void
    {
        if (isset($this->laMetricIconFailures[$laMetricIconId])) {
            throw $this->laMetricIconFailures[$laMetricIconId];
        }

        $this->failIfConfigured();

        $this->downloadedLaMetricIcons[] = ['id' => $laMetricIconId, 'name' => $iconName];
    }

    public function setIndicator(int $slot, IndicatorPayload $indicator): void
    {
        $this->failIfConfigured();

        $this->setIndicators[] = ['slot' => $slot, 'indicator' => $indicator];
    }

    public function clearIndicator(int $slot): void
    {
        $this->failIfConfigured();

        $this->clearedIndicatorSlots[] = $slot;
    }

    public function reboot(): void
    {
        $this->failIfConfigured();

        ++$this->rebootCount;
    }

    private function failIfConfigured(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
