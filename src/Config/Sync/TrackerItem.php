<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\Color;

final readonly class TrackerItem
{
    public function __construct(
        public string $symbol,
        public string $currency,
        public ?string $icon = null,
        public ?string $label = null,
        public ?Color $labelColor = null,
        public ?string $bottomText = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options options already validated against pixelcast.schema.json
     * @param string $itemPath the path of the item including its index, e.g. syncs.coingecko.items[0]
     */
    public static function fromOptions(array $options, string $itemPath): self
    {
        return new self(
            symbol: SyncOptionReader::requireString($options, 'symbol', $itemPath),
            currency: SyncOptionReader::requireString($options, 'currency', $itemPath),
            icon: SyncOptionReader::optionalString($options, 'icon', $itemPath),
            label: SyncOptionReader::optionalString($options, 'label', $itemPath),
            labelColor: SyncOptionReader::optionalColor($options, 'labelColor', $itemPath),
            bottomText: SyncOptionReader::optionalString($options, 'bottomText', $itemPath),
        );
    }
}
