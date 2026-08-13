<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\StaleBehavior;
use App\Message\SyncMessage;
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

    /**
     * The bottom lines this group can fill. A group offers the all-time high only when it knows a
     * high it did not observe itself, otherwise a fresh install would read "we are at the all-time
     * high" on any asset from day one.
     *
     * @return list<BottomLine>
     */
    abstract public static function acceptedBottomLines(): array;

    public static function fromOptions(array $options): static
    {
        $optionsPath = 'syncs.'.static::syncType();

        $interval = SyncInterval::fromOptions($options, $optionsPath);
        $staleDeclaration = StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::cases());

        $items = [];
        foreach (SyncOptionReader::requireListOfMaps($options, 'items', $optionsPath) as $index => $itemOptions) {
            $items[] = TrackerItem::fromOptions(
                $itemOptions,
                \sprintf('%s.items[%d]', $optionsPath, $index),
                $staleDeclaration,
                static::acceptedBottomLines(),
            );
        }

        return new static(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            staleDeclaration: $staleDeclaration,
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath),
            items: $items,
        );
    }

    public function syncMessage(): SyncMessage
    {
        return new SyncTrackerMessage(static::syncType());
    }

    /**
     * The items worth fetching at this instant: those following the group, and those whose own
     * window is open. The provider quota is spent per item, so filtering here is what saves it.
     * A null instant asks for every item, whatever its window.
     *
     * @return list<TrackerItem>
     */
    public function itemsToFetchAt(?\DateTimeImmutable $instant): array
    {
        if (null === $instant) {
            return $this->items;
        }

        return array_values(array_filter(
            $this->items,
            static fn (TrackerItem $item): bool => null === $item->activeWindow || $item->activeWindow->contains($instant),
        ));
    }

    public function activityAt(\DateTimeImmutable $instant): SyncGroupActivity
    {
        $groupWindow = $this->activeWindow;

        if (null !== $groupWindow && !$groupWindow->contains($instant)) {
            return SyncGroupActivity::inactive();
        }

        $activeItems = $this->itemsToFetchAt($instant);
        if ([] === $activeItems) {
            return SyncGroupActivity::inactive();
        }

        $groupActivity = SyncGroupActivity::activeSince($groupWindow?->secondsSinceOpening($instant));
        $itemsActivity = SyncGroupActivity::activeSince(self::secondsSinceTheEarliestItemOpening($activeItems, $instant));

        return $groupActivity->combinedWith($itemsActivity);
    }

    /**
     * @param list<TrackerItem> $activeItems
     */
    private static function secondsSinceTheEarliestItemOpening(array $activeItems, \DateTimeImmutable $instant): ?int
    {
        $secondsSinceTheEarliestOpening = 0;

        foreach ($activeItems as $activeItem) {
            $itemWindow = $activeItem->activeWindow;
            if (null === $itemWindow) {
                return null;
            }

            $secondsSinceTheEarliestOpening = max($secondsSinceTheEarliestOpening, $itemWindow->secondsSinceOpening($instant));
        }

        return $secondsSinceTheEarliestOpening;
    }
}
