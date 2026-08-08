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

        $interval = SyncInterval::fromOptions($options, $optionsPath);
        $staleDeclaration = StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::cases());

        $items = [];
        foreach (SyncOptionReader::requireListOfMaps($options, 'items', $optionsPath) as $index => $itemOptions) {
            $items[] = TrackerItem::fromOptions($itemOptions, \sprintf('%s.items[%d]', $optionsPath, $index), $staleDeclaration);
        }

        return new static(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            staleDeclaration: $staleDeclaration,
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath),
            items: $items,
        );
    }

    public function syncMessage(): object
    {
        return new SyncTrackerMessage(static::syncType());
    }

    /**
     * The items worth fetching at this instant: those following the group, and those whose own
     * window is open. The provider quota is spent per item, so filtering here is what saves it.
     *
     * @return list<TrackerItem>
     */
    public function activeItemsAt(\DateTimeImmutable $instant): array
    {
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

        $activeItems = $this->activeItemsAt($instant);
        if ([] === $activeItems) {
            return SyncGroupActivity::inactive();
        }

        return SyncGroupActivity::activeSince(self::secondsSinceTheLaterOpening(
            $groupWindow?->secondsSinceOpening($instant),
            self::secondsSinceTheEarliestItemOpening($activeItems, $instant),
        ));
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

    /**
     * The dispatchable stretch begins at the later of the two openings, so at the smaller count of
     * seconds. Null stands for an opening infinitely far back, which any count is later than.
     */
    private static function secondsSinceTheLaterOpening(?int $firstOpening, ?int $secondOpening): ?int
    {
        if (null === $firstOpening) {
            return $secondOpening;
        }

        if (null === $secondOpening) {
            return $firstOpening;
        }

        return min($firstOpening, $secondOpening);
    }
}
