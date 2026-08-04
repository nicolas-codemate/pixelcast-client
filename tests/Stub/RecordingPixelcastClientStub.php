<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\Notification\NotificationPayload;
use App\Client\PixelcastClientInterface;
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
     * @var list<NotificationPayload>
     */
    public array $pushedNotifications = [];

    public int $dismissedNotificationCount = 0;

    public function __construct(
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function pushWeather(WeatherPayload $weather): void
    {
        $this->failIfConfigured();

        $this->pushedPayloads[] = $weather;
    }

    public function pushTracker(TrackerPayload $tracker): void
    {
        $this->failIfConfigured();

        $this->pushedTrackers[] = $tracker;
    }

    public function deleteTracker(string $trackerName): void
    {
        $this->failIfConfigured();

        $this->deletedTrackerNames[] = $trackerName;
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

    private function failIfConfigured(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
