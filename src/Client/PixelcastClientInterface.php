<?php

declare(strict_types=1);

namespace App\Client;

use App\Client\Exception\DeviceBusyException;
use App\Client\Exception\DeviceUnreachableException;
use App\Client\Exception\InvalidPayloadException;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Gauge\GaugePayload;
use App\Client\Notification\NotificationPayload;
use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepState;
use App\Client\Tracker\TrackerPayload;
use App\Client\Weather\WeatherPayload;

/**
 * Every method below maps a device failure the same way:
 * {@see InvalidPayloadException} when the request does not match the device spec (local check or HTTP 400),
 * {@see ResourceNotFoundException} on HTTP 404,
 * {@see DeviceBusyException} when the device cannot accept the request right now (HTTP 500 or 503),
 * {@see DeviceUnreachableException} when the device did not answer, or answered an unmapped status.
 */
interface PixelcastClientInterface
{
    public function pushWeather(WeatherPayload $weather): void;

    public function pushTracker(TrackerPayload $tracker): void;

    /**
     * @throws ResourceNotFoundException no tracker carries this name
     */
    public function deleteTracker(string $trackerName): void;

    public function pushGauge(GaugePayload $gauge): void;

    public function pushNotification(NotificationPayload $notification): void;

    /**
     * @throws ResourceNotFoundException there is no notification to dismiss
     */
    public function dismissNotification(): void;

    public function pushSleepConfiguration(SleepPayload $sleep): void;

    public function fetchSleepState(): SleepState;
}
