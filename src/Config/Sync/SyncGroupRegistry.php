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
        BoursoramaSyncConfig::class,
        ClaudeSyncConfig::class,
    ];

    /**
     * @var array<string, class-string<SyncGroupConfig>>|null
     */
    private static ?array $syncGroupClassesByType = null;

    /**
     * @return list<string>
     */
    public static function syncTypes(): array
    {
        return array_keys(self::syncGroupClassesByType());
    }

    /**
     * @return class-string<SyncGroupConfig>
     */
    public static function syncGroupClassFor(string $syncType): string
    {
        return self::syncGroupClassesByType()[$syncType]
            ?? throw new \LogicException(\sprintf('No configuration class declares the sync group "%s".', $syncType));
    }

    /**
     * @return array<string, class-string<SyncGroupConfig>>
     */
    private static function syncGroupClassesByType(): array
    {
        if (null !== self::$syncGroupClassesByType) {
            return self::$syncGroupClassesByType;
        }

        $syncGroupClassesByType = [];
        foreach (self::SYNC_GROUP_CLASSES as $syncGroupClass) {
            $syncGroupClassesByType[$syncGroupClass::syncType()] = $syncGroupClass;
        }

        return self::$syncGroupClassesByType = $syncGroupClassesByType;
    }
}
