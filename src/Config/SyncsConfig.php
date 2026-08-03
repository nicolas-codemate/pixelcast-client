<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncGroupConfig;

final readonly class SyncsConfig
{
    /**
     * @param array<string, SyncGroupConfig> $syncGroups keyed by sync type, in the order of the configuration file
     */
    public function __construct(
        private array $syncGroups,
    ) {
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
