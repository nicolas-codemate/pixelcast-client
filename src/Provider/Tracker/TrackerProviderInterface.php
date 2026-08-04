<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
use App\Config\Exception\PixelCastConfigException;

interface TrackerProviderInterface
{
    /**
     * @return list<TrackerPayload>
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchTrackers(): array;
}
