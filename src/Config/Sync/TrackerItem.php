<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\Color;
use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;

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
        public ?BottomLine $bottomLine = null,
        public bool $volumeBars = true,
        public ?int $lametricId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options options already validated against pixelcast.schema.json
     * @param string $itemPath the path of the item including its index, e.g. syncs.coingecko.items[0]
     * @param StaleDeclaration $groupStaleDeclaration the declaration of the group, which the item follows unless it declares its own
     * @param list<BottomLine> $acceptedBottomLines the bottom lines the group of this item can fill
     */
    public static function fromOptions(array $options, string $itemPath, StaleDeclaration $groupStaleDeclaration, array $acceptedBottomLines, ?\DateTimeZone $deviceTimezone = null): self
    {
        return new self(
            symbol: SyncOptionReader::requireString($options, 'symbol', $itemPath),
            currency: SyncOptionReader::requireString($options, 'currency', $itemPath),
            icon: SyncOptionReader::optionalString($options, 'icon', $itemPath),
            staleDeclaration: StaleDeclaration::inheritedFrom($options, $itemPath, $groupStaleDeclaration, StaleBehavior::cases()),
            label: SyncOptionReader::optionalString($options, 'label', $itemPath),
            labelColor: SyncOptionReader::optionalColor($options, 'labelColor', $itemPath),
            bottomText: SyncOptionReader::optionalString($options, 'bottomText', $itemPath),
            activeWindow: ActiveWindow::optionalFromOptions($options, $itemPath, $deviceTimezone),
            bottomLine: self::readBottomLine($options, $itemPath, $acceptedBottomLines),
            volumeBars: SyncOptionReader::optionalBool($options, 'volumeBars', $itemPath) ?? true,
            lametricId: SyncOptionReader::optionalInt($options, 'lametricId', $itemPath, 1, \PHP_INT_MAX),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param list<BottomLine> $acceptedBottomLines
     */
    private static function readBottomLine(array $options, string $itemPath, array $acceptedBottomLines): ?BottomLine
    {
        if ([] === $acceptedBottomLines) {
            if (SyncOptionReader::isDeclared($options, 'bottomLine')) {
                throw PixelCastConfigException::invalidValue($itemPath.'.bottomLine', 'this group serves no bottom line');
            }

            return null;
        }

        return SyncOptionReader::optionalEnum($options, 'bottomLine', $itemPath, $acceptedBottomLines);
    }
}
