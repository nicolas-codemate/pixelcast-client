<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Device\BrightnessSchedule;
use App\Config\Device\DeviceConfig;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sleep\DeviceSleepConfig;
use App\Config\Sleep\SleepSchedule;
use App\Config\Sync\SyncGroupConfig;
use App\Config\Sync\TrackerSyncConfig;

/**
 * Everything pixelcast.yaml declares, which is more than its sync groups: the sleep schedule is a
 * device setting no group ever runs.
 */
final readonly class SyncsConfig
{
    /**
     * @param array<string, SyncGroupConfig> $syncGroups keyed by sync type, in the order of the configuration file
     */
    public function __construct(
        private array $syncGroups,
        public ?DeviceSleepConfig $deviceSleep = null,
        public ?DeviceConfig $device = null,
    ) {
    }

    public function sleepSchedule(): ?SleepSchedule
    {
        return $this->deviceSleep?->sleepSchedule();
    }

    public function brightnessSchedule(): ?BrightnessSchedule
    {
        return $this->device?->brightnessSchedule;
    }

    /**
     * @return array<string, SyncGroupConfig>
     */
    public function enabledSyncGroups(): array
    {
        return array_filter(
            $this->syncGroups,
            static fn (SyncGroupConfig $syncGroup): bool => $syncGroup->enabled,
        );
    }

    /**
     * Every group that tracks assets, enabled or not: a group about to be enabled deserves its
     * all-time highs caught up first.
     *
     * @return array<string, TrackerSyncConfig>
     */
    public function trackerSyncGroups(): array
    {
        $trackerSyncGroups = [];

        foreach ($this->syncGroups as $syncType => $syncGroup) {
            if ($syncGroup instanceof TrackerSyncConfig) {
                $trackerSyncGroups[$syncType] = $syncGroup;
            }
        }

        return $trackerSyncGroups;
    }

    /**
     * @template TSyncGroupConfig of SyncGroupConfig
     *
     * @param class-string<TSyncGroupConfig> $syncGroupClass
     *
     * @return TSyncGroupConfig
     */
    public function syncGroupOfType(string $syncGroupClass): SyncGroupConfig
    {
        $syncType = $syncGroupClass::syncType();
        $syncGroup = $this->syncGroups[$syncType] ?? null;

        if (!$syncGroup instanceof $syncGroupClass) {
            throw PixelCastConfigException::missingKey('syncs.'.$syncType);
        }

        return $syncGroup;
    }
}
