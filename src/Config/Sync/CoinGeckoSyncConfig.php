<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Message\SyncTrackerMessage;

final readonly class CoinGeckoSyncConfig implements SyncGroupConfig
{
    /**
     * @param list<TrackerItem> $items
     */
    public function __construct(
        public bool $enabled,
        public SyncInterval $interval,
        public array $items,
    ) {
    }

    public static function syncType(): string
    {
        return 'coingecko';
    }

    public static function fromOptions(array $options, string $optionsPath): self
    {
        $rawInterval = SyncOptionReader::requireString($options, 'interval', $optionsPath);

        $items = [];
        foreach (SyncOptionReader::requireListOfMaps($options, 'items', $optionsPath) as $index => $itemOptions) {
            $items[] = TrackerItem::fromOptions($itemOptions, \sprintf('%s.items[%d]', $optionsPath, $index));
        }

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: SyncInterval::fromOption($rawInterval, $optionsPath.'.interval'),
            items: $items,
        );
    }

    public function syncMessage(): object
    {
        return new SyncTrackerMessage(self::syncType());
    }
}
