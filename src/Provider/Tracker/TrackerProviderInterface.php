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
     * @return list<TrackerPayload>
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchTrackers(): array;
}
