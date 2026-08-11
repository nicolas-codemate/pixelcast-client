<?php

declare(strict_types=1);

namespace App\Tracker;

use App\Config\Sync\TrackerItem;

/**
 * A configured tracker item together with the group that fetches it.
 */
final readonly class TrackedAsset
{
    /**
     * A symbol alone does not name an asset, since the same one can be tracked in two currencies.
     */
    public string $label;

    public function __construct(
        public string $syncType,
        public TrackerItem $item,
    ) {
        $this->label = \sprintf('%s %s (%s)', $syncType, $item->symbol, mb_strtoupper($item->currency));
    }
}
