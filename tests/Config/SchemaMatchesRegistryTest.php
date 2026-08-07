<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\SyncGroupRegistry;
use App\Config\Sync\TrackerItem;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;

/**
 * The sync groups and the fields of a tracker item are declared twice, in pixelcast.schema.json
 * and in the PHP classes that read them.
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

    public function testTheSchemaAndTheTrackerItemDeclareTheSameFields(): void
    {
        $trackerItemProperties = self::trackerItemPropertiesDeclaredBySchema();
        $constructorParameters = self::trackerItemConstructorParameters();

        $fieldNamesFromSchema = array_keys($trackerItemProperties);
        $fieldNamesFromTrackerItem = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructorParameters,
        );

        sort($fieldNamesFromSchema);
        sort($fieldNamesFromTrackerItem);

        self::assertSame($fieldNamesFromTrackerItem, $fieldNamesFromSchema);
    }

    public function testTheSchemaRequiresExactlyTheTrackerItemFieldsWithoutADefault(): void
    {
        $requiredFieldNamesFromSchema = self::trackerItemRequiredFieldsDeclaredBySchema();
        $fieldNamesWithoutADefault = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            array_values(array_filter(
                self::trackerItemConstructorParameters(),
                static fn (\ReflectionParameter $parameter): bool => !$parameter->isDefaultValueAvailable(),
            )),
        );

        sort($requiredFieldNamesFromSchema);
        sort($fieldNamesWithoutADefault);

        self::assertSame($fieldNamesWithoutADefault, $requiredFieldNamesFromSchema);
    }

    public function testTheSchemaBoundsTheTextFieldsToTheLengthsTheDeviceAccepts(): void
    {
        $trackerItemProperties = self::trackerItemPropertiesDeclaredBySchema();

        self::assertSame(TrackerPayload::MAXIMUM_SYMBOL_LENGTH, $trackerItemProperties['label']['maxLength'] ?? null);
        self::assertSame(TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH, $trackerItemProperties['bottomText']['maxLength'] ?? null);
    }

    /**
     * @return list<string>
     */
    private static function syncTypesDeclaredBySchema(): array
    {
        $schema = self::decodedSchema();
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['properties']['syncs']);
        self::assertIsArray($schema['properties']['syncs']['properties']);

        return array_map(strval(...), array_keys($schema['properties']['syncs']['properties']));
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function trackerItemPropertiesDeclaredBySchema(): array
    {
        $trackerItem = self::trackerItemDeclaredBySchema();
        self::assertIsArray($trackerItem['properties']);

        $properties = [];
        foreach ($trackerItem['properties'] as $fieldName => $fieldSchema) {
            self::assertIsArray($fieldSchema);
            $properties[(string) $fieldName] = $fieldSchema;
        }

        return $properties;
    }

    /**
     * @return list<string>
     */
    private static function trackerItemRequiredFieldsDeclaredBySchema(): array
    {
        $trackerItem = self::trackerItemDeclaredBySchema();
        self::assertIsArray($trackerItem['required']);

        $requiredFieldNames = [];
        foreach ($trackerItem['required'] as $fieldName) {
            self::assertIsString($fieldName);
            $requiredFieldNames[] = $fieldName;
        }

        return $requiredFieldNames;
    }

    /**
     * @return array<mixed>
     */
    private static function trackerItemDeclaredBySchema(): array
    {
        $schema = self::decodedSchema();
        self::assertIsArray($schema['definitions']);
        self::assertIsArray($schema['definitions']['trackerItem']);

        return $schema['definitions']['trackerItem'];
    }

    /**
     * @return list<\ReflectionParameter>
     */
    private static function trackerItemConstructorParameters(): array
    {
        $constructor = new \ReflectionClass(TrackerItem::class)->getConstructor();
        self::assertNotNull($constructor);

        return $constructor->getParameters();
    }

    /**
     * @return array<mixed>
     */
    private static function decodedSchema(): array
    {
        $rawSchema = file_get_contents(SyncsConfigLoaderFactory::projectFilePath('pixelcast.schema.json'));
        self::assertIsString($rawSchema);

        $schema = json_decode($rawSchema, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);

        return $schema;
    }
}
