<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\Color;
use App\Client\StaleBehavior;

final readonly class TrackerItem
{
    public function __construct(
        public string $symbol,
        public string $currency,
        public ?string $icon,
        public StaleDeclaration $staleDeclaration,
        public ?string $label = null,
        public ?Color $labelColor = null,
        public ?string $bottomText = null,
        public ?ActiveWindow $activeWindow = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options options already validated against pixelcast.schema.json
     * @param string $itemPath the path of the item including its index, e.g. syncs.coingecko.items[0]
     * @param StaleDeclaration $groupStaleDeclaration the declaration of the group, which the item follows unless it declares its own
     */
    public static function fromOptions(array $options, string $itemPath, StaleDeclaration $groupStaleDeclaration): self
    {
        return new self(
            symbol: SyncOptionReader::requireString($options, 'symbol', $itemPath),
            currency: SyncOptionReader::requireString($options, 'currency', $itemPath),
            icon: SyncOptionReader::optionalString($options, 'icon', $itemPath),
            staleDeclaration: StaleDeclaration::inheritedFrom($options, $itemPath, $groupStaleDeclaration, StaleBehavior::cases()),
            label: SyncOptionReader::optionalString($options, 'label', $itemPath),
            labelColor: SyncOptionReader::optionalColor($options, 'labelColor', $itemPath),
            bottomText: SyncOptionReader::optionalString($options, 'bottomText', $itemPath),
            activeWindow: ActiveWindow::optionalFromOptions($options, $itemPath),
        );
    }
}
