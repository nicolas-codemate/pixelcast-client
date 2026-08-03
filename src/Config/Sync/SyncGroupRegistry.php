<?php

declare(strict_types=1);

namespace App\Config\Sync;

final class SyncGroupRegistry
{
    /**
     * Single place where a sync group is declared: adding a group means adding its
     * configuration class here and describing its options in pixelcast.schema.json.
     *
     * @var list<class-string<SyncGroupConfig>>
     */
    private const array SYNC_GROUP_CLASSES = [
        WeatherSyncConfig::class,
        CoinGeckoSyncConfig::class,
        TwelveDataSyncConfig::class,
    ];

    /**
     * @return list<string>
     */
    public static function syncTypes(): array
    {
        $syncTypes = [];
        foreach (self::SYNC_GROUP_CLASSES as $syncGroupClass) {
            $syncTypes[] = $syncGroupClass::syncType();
        }

        return $syncTypes;
    }

    /**
     * @return class-string<SyncGroupConfig>|null
     */
    public static function syncGroupClassFor(string $syncType): ?string
    {
        foreach (self::SYNC_GROUP_CLASSES as $syncGroupClass) {
            if ($syncGroupClass::syncType() === $syncType) {
                return $syncGroupClass;
            }
        }

        return null;
    }
}
