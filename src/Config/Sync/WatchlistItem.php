<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\Color;

/**
 * One quote of the watchlist, drawn as one row: its label, its price in its currency, its change.
 */
final readonly class WatchlistItem
{
    public function __construct(
        public WatchlistQuoteSource $source,
        public string $symbol,
        public string $currency,
        public ?string $label,
        public ?Color $labelColor,
        public ?string $icon,
        public ?int $lametricId,
    ) {
    }

    /**
     * @param array<string, mixed> $options options already validated against pixelcast.schema.json
     * @param string $itemPath the path of the item including its index, e.g. syncs.watchlist.items[0]
     */
    public static function fromOptions(array $options, string $itemPath): self
    {
        return new self(
            source: SyncOptionReader::requireEnum($options, 'source', $itemPath, WatchlistQuoteSource::class),
            symbol: SyncOptionReader::requireString($options, 'symbol', $itemPath),
            currency: SyncOptionReader::requireString($options, 'currency', $itemPath),
            label: SyncOptionReader::optionalString($options, 'label', $itemPath),
            labelColor: SyncOptionReader::optionalColor($options, 'labelColor', $itemPath),
            icon: SyncOptionReader::optionalString($options, 'icon', $itemPath),
            lametricId: SyncOptionReader::optionalInt($options, 'lametricId', $itemPath, 1),
        );
    }
}
