<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Sync\SyncGroupRegistry;
use App\Config\SyncsConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * pixelcast.yaml.dist is what a host copies, so the loader must accept it as it ships.
 */
final class DistConfigTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__.'/../..';

    public function testTheDistFileOnlyEnablesTheWeatherGroup(): void
    {
        $config = self::distLoader()->load();

        self::assertSame(['weather'], array_keys($config->enabledSyncGroups()));
    }

    public function testTheDistFileDocumentsEverySyncGroupOfTheRegistry(): void
    {
        $config = self::distLoader()->load();

        foreach (SyncGroupRegistry::syncTypes() as $syncType) {
            $syncGroupClass = SyncGroupRegistry::syncGroupClassFor($syncType);
            self::assertNotNull($syncGroupClass);

            self::assertSame($syncType, $config->syncGroupOfType($syncGroupClass)::syncType());
        }
    }

    private static function distLoader(): SyncsConfigLoader
    {
        return new SyncsConfigLoader(self::PROJECT_DIR.'/pixelcast.yaml.dist', self::PROJECT_DIR.'/pixelcast.schema.json');
    }
}
