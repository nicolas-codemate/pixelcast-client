<?php

declare(strict_types=1);

namespace App\Tracker\History;

use App\Config\Sync\TrackerItem;
use App\Tracker\AllTimeHigh;

/**
 * A source that can be asked, outside the scheduler, for the deepest history it serves on a tracked
 * asset. Groups whose provider already records their high on every sync have no source here.
 */
interface AllTimeHighSourceInterface
{
    public function syncType(): string;

    /**
     * The deepest high this source can serve, carrying the day it was reached on, or null when the
     * source knows nothing about that asset.
     */
    public function fetchAllTimeHigh(TrackerItem $item, \DateTimeImmutable $observedAt): ?AllTimeHigh;
}
