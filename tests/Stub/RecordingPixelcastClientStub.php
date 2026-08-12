<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\Gauge\GaugePayload;
use App\Client\Notification\NotificationPayload;
use App\Client\PixelcastClientInterface;
use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepState;
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
     * @var list<NotificationPayload>
     */
    public array $pushedNotifications = [];

    /**
     * @var list<SleepPayload>
     */
    public array $pushedSleepPayloads = [];

    public ?SleepState $sleepStateToReturn = null;

    public int $dismissedNotificationCount = 0;

    /**
     * @param array<string, \Throwable> $trackerFailures keyed by tracker name
     */
    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly array $trackerFailures = [],
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
        $this->failIfConfigured();

        return $this->sleepStateToReturn ?? SleepState::fromResponseBody(['sleeping' => false, 'config' => []]);
    }

    private function failIfConfigured(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
