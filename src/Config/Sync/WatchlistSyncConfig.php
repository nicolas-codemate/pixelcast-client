<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\StaleBehavior;
use App\Client\Watchlist\WatchlistPayload;
use App\Config\Exception\PixelCastConfigException;
use App\Message\SyncMessage;
use App\Message\SyncWatchlistMessage;

/**
 * Quotes pushed together as one watchlist app, three rows to a page. Unlike a tracker group, every
 * item is fetched on every cycle whatever market it trades on: a row missing from the list would
 * move every row below it, while a closed market still has a last price to show.
 */
final readonly class WatchlistSyncConfig implements SyncGroupConfig
{
    public const int MINIMUM_ITEMS = 1;
    public const int MAXIMUM_ITEMS = WatchlistPayload::MAXIMUM_ROWS;

    /**
     * @param list<WatchlistItem> $items
     */
    public function __construct(
        public bool $enabled,
        public SyncInterval $interval,
        public StaleDeclaration $staleDeclaration,
        public ?ActiveWindow $activeWindow,
        public array $items,
    ) {
    }

    public static function syncType(): string
    {
        return 'watchlist';
    }

    public static function fromOptions(array $options, ?\DateTimeZone $deviceTimezone): self
    {
        $optionsPath = 'syncs.'.self::syncType();

        $interval = SyncInterval::fromOptions($options, $optionsPath);

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            staleDeclaration: StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::cases()),
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath, $deviceTimezone),
            items: self::readItems($options, $optionsPath),
        );
    }

    public function syncMessage(): SyncMessage
    {
        return new SyncWatchlistMessage();
    }

    public function activityAt(\DateTimeImmutable $instant): SyncGroupActivity
    {
        $activeWindow = $this->activeWindow;

        if (null !== $activeWindow && !$activeWindow->contains($instant)) {
            return SyncGroupActivity::inactive();
        }

        return SyncGroupActivity::activeSince($activeWindow?->secondsSinceOpening($instant));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<WatchlistItem>
     */
    private static function readItems(array $options, string $optionsPath): array
    {
        $itemsPath = $optionsPath.'.items';
        $itemOptionMaps = SyncOptionReader::requireListOfMaps($options, 'items', $optionsPath);

        $itemCount = \count($itemOptionMaps);
        if ($itemCount < self::MINIMUM_ITEMS || $itemCount > self::MAXIMUM_ITEMS) {
            throw PixelCastConfigException::invalidValue($itemsPath, \sprintf('expected between %d and %d quotes, got %d', self::MINIMUM_ITEMS, self::MAXIMUM_ITEMS, $itemCount));
        }

        $items = [];
        foreach ($itemOptionMaps as $index => $itemOptions) {
            $items[] = WatchlistItem::fromOptions($itemOptions, \sprintf('%s[%d]', $itemsPath, $index));
        }

        return $items;
    }
}
