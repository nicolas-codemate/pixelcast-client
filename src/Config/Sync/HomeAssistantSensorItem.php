<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\Color;

/**
 * One Home Assistant entity drawn as one zone of the custom app: its value on the zone text, the
 * room it measures on the zone label.
 */
final readonly class HomeAssistantSensorItem
{
    public function __construct(
        public string $entityId,
        public string $label,
        public ?string $iconName,
        public ?Color $valueColor,
    ) {
    }

    /**
     * @param array<string, mixed> $options options already validated against pixelcast.schema.json
     * @param string $itemPath the path of the item including its index, e.g. syncs.homeassistant.items[0]
     */
    public static function fromOptions(array $options, string $itemPath): self
    {
        return new self(
            entityId: SyncOptionReader::requireString($options, 'entity', $itemPath),
            label: SyncOptionReader::requireString($options, 'label', $itemPath),
            iconName: SyncOptionReader::optionalString($options, 'icon', $itemPath),
            valueColor: SyncOptionReader::optionalColor($options, 'color', $itemPath),
        );
    }
}
