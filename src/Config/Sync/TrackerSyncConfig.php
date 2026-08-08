<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\StaleBehavior;
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
        public StaleDeclaration $staleDeclaration,
        public ?ActiveWindow $activeWindow,
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

        $interval = SyncInterval::fromOptions($options, $optionsPath);

        return new static(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            staleDeclaration: StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::cases()),
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath),
            items: $items,
        );
    }

    public function syncMessage(): object
    {
        return new SyncTrackerMessage(static::syncType());
    }
}
