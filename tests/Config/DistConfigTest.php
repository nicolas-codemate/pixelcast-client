<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Sync\SyncGroupRegistry;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * pixelcast.yaml.dist is what a host copies, so the loader must accept it as it ships.
 */
final class DistConfigTest extends TestCase
{
    private const string DIST_FILE = 'pixelcast.yaml.dist';

    public function testTheDistFileOnlyEnablesTheWeatherGroup(): void
    {
        $config = SyncsConfigLoaderFactory::forConfigFile(SyncsConfigLoaderFactory::projectFilePath(self::DIST_FILE))->load();

        self::assertSame(['weather'], array_keys($config->enabledSyncGroups()));
    }

    public function testTheDistFileLetsTheSleepScheduleInheritTheDeviceTimezone(): void
    {
        $config = SyncsConfigLoaderFactory::forConfigFile(SyncsConfigLoaderFactory::projectFilePath(self::DIST_FILE))->load();

        self::assertNotNull($config->device);
        self::assertNotNull($config->device->timezone);
        self::assertSame('Europe/Paris', $config->device->timezone->getName());

        self::assertNotNull($config->deviceSleep);
        self::assertNotNull($config->deviceSleep->timezone);
        self::assertSame('Europe/Paris', $config->deviceSleep->timezone->getName());
    }

    public function testTheDistFileDocumentsEverySyncGroupOfTheRegistry(): void
    {
        $syncTypesFromDistFile = self::syncTypesDocumentedByDistFile();
        $syncTypesFromRegistry = SyncGroupRegistry::syncTypes();

        sort($syncTypesFromDistFile);
        sort($syncTypesFromRegistry);

        self::assertSame($syncTypesFromRegistry, $syncTypesFromDistFile);
    }

    /**
     * The LaMetric database serves nothing that reads as an ETF, so that icon is uploaded by hand
     * and is the one skip `make icons-seed` is expected to report on this file.
     */
    public function testTheDistFileMapsEveryTrackerIconButTheEtfOne(): void
    {
        $config = SyncsConfigLoaderFactory::forConfigFile(SyncsConfigLoaderFactory::projectFilePath(self::DIST_FILE))->load();

        /** @var array<string, int|null> $lametricIdByIconName */
        $lametricIdByIconName = [];

        foreach ($config->trackerSyncGroups() as $trackerSyncGroup) {
            foreach ($trackerSyncGroup->items as $item) {
                if (null === $item->icon) {
                    continue;
                }

                // Two items can share one icon name while only one of them carries the mapping.
                $lametricIdByIconName[$item->icon] ??= $item->lametricId;
            }
        }

        ksort($lametricIdByIconName);

        self::assertSame(['bitcoin' => 15392, 'etf' => null, 'stock' => 40160], $lametricIdByIconName);
    }

    /**
     * @return list<string>
     */
    private static function syncTypesDocumentedByDistFile(): array
    {
        $distTree = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath(self::DIST_FILE));
        self::assertIsArray($distTree);
        self::assertIsArray($distTree['syncs']);

        return array_map(strval(...), array_keys($distTree['syncs']));
    }
}
