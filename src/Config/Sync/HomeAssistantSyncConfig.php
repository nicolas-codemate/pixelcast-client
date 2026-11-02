<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\CustomApp\CustomAppPayload;
use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Message\SyncHomeAssistantMessage;
use App\Message\SyncMessage;

/**
 * Home Assistant sensors, one custom app holding a zone per sensor. Home Assistant owns the link
 * to the sensors themselves, Netatmo OAuth included, so this group only reads the states it
 * already holds.
 *
 * No credential lives here either. The address and the token are read from
 * PIXELCAST_HOMEASSISTANT_URL and PIXELCAST_HOMEASSISTANT_TOKEN, like every other secret of this
 * application.
 */
final readonly class HomeAssistantSyncConfig implements SyncGroupConfig
{
    /**
     * A single sensor is drawn as a single-zone app, and the multi-zone layout holds four zones
     * at most.
     */
    public const int MINIMUM_ITEMS = 1;
    public const int MAXIMUM_ITEMS = CustomAppPayload::MAXIMUM_ZONES;

    /**
     * @param list<HomeAssistantSensorItem> $items
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
        return 'homeassistant';
    }

    public static function fromOptions(array $options, ?\DateTimeZone $deviceTimezone): self
    {
        $optionsPath = 'syncs.'.self::syncType();

        $interval = SyncInterval::fromOptions($options, $optionsPath);

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            // Two behaviours only, like the github group: the custom app layout draws neither `dim` nor `badge`.
            staleDeclaration: StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::ACCEPTED_OUTSIDE_TRACKER_AND_GAUGE),
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath, $deviceTimezone),
            items: self::readItems($options, $optionsPath),
        );
    }

    public function syncMessage(): SyncMessage
    {
        return new SyncHomeAssistantMessage();
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
     * @return list<HomeAssistantSensorItem>
     */
    private static function readItems(array $options, string $optionsPath): array
    {
        $itemsPath = $optionsPath.'.items';
        $itemOptionMaps = SyncOptionReader::requireListOfMaps($options, 'items', $optionsPath);

        $itemCount = \count($itemOptionMaps);
        if ($itemCount < self::MINIMUM_ITEMS || $itemCount > self::MAXIMUM_ITEMS) {
            throw PixelCastConfigException::invalidValue($itemsPath, \sprintf('expected between %d and %d sensors, got %d', self::MINIMUM_ITEMS, self::MAXIMUM_ITEMS, $itemCount));
        }

        $items = [];
        foreach ($itemOptionMaps as $index => $itemOptions) {
            $items[] = HomeAssistantSensorItem::fromOptions($itemOptions, \sprintf('%s[%d]', $itemsPath, $index));
        }

        return $items;
    }
}
