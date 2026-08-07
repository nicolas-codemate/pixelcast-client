<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Sync\SyncGroupRegistry;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;

/**
 * The known sync groups are declared twice, in pixelcast.schema.json and in SyncGroupRegistry.
 */
final class SchemaMatchesRegistryTest extends TestCase
{
    public function testTheSchemaAndTheRegistryDeclareTheSameSyncGroups(): void
    {
        $syncTypesFromSchema = self::syncTypesDeclaredBySchema();
        $syncTypesFromRegistry = SyncGroupRegistry::syncTypes();

        sort($syncTypesFromSchema);
        sort($syncTypesFromRegistry);

        self::assertSame($syncTypesFromRegistry, $syncTypesFromSchema);
    }

    /**
     * @return list<string>
     */
    private static function syncTypesDeclaredBySchema(): array
    {
        $rawSchema = file_get_contents(SyncsConfigLoaderFactory::projectFilePath('pixelcast.schema.json'));
        self::assertIsString($rawSchema);

        $schema = json_decode($rawSchema, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['properties']['syncs']);
        self::assertIsArray($schema['properties']['syncs']['properties']);

        return array_map(strval(...), array_keys($schema['properties']['syncs']['properties']));
    }
}
