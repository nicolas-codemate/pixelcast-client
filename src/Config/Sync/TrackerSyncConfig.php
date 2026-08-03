<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Message\SyncTrackerMessage;

/**
 * Shared shape of the groups that track assets from a single provider, mirroring
 * the `definitions/trackerSync` of pixelcast.schema.json.
 */
abstract readonly class TrackerSyncConfig implements SyncGroupConfig
{
    /**
     * @param list<TrackerItem> $items
     */
    final public function __construct(
        public bool $enabled,
        public SyncInterval $interval,
        public array $items,
    ) {
    }

    public static function fromOptions(array $options): static
    {
        $optionsPath = 'syncs.'.static::syncType();

        $items = [];
        foreach (SyncOptionReader::requireListOfMaps($options, 'items', $optionsPath) as $index => $itemOptions) {
            $items[] = TrackerItem::fromOptions($itemOptions, \sprintf('%s.items[%d]', $optionsPath, $index));
        }

        return new static(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: SyncInterval::fromOptions($options, $optionsPath),
            items: $items,
        );
    }

    public function syncMessage(): object
    {
        return new SyncTrackerMessage(static::syncType());
    }
}
