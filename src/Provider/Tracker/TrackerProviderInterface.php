<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
use App\Config\Exception\PixelCastConfigException;

interface TrackerProviderInterface
{
    /**
     * The sync type of the group this provider serves, as declared in SyncGroupRegistry.
     */
    public function syncType(): string;

    /**
     * @param \DateTimeImmutable|null $activeWindowInstant the instant the item windows are judged at, or
     *                                                     null to fetch every item whatever its window
     *
     * @return list<TrackerPayload>
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchTrackers(?\DateTimeImmutable $activeWindowInstant): array;
}
