<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\TrackerItem;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The fields of a tracker item are declared twice, in pixelcast.schema.json and in TrackerItem.
 * Their text lengths are declared a third time, by the device contract sync/schemas/tracker.yaml
 * copies from the firmware repository.
 */
final class TrackerItemMatchesSchemaTest extends TestCase
{
    /**
     * The two freshness keys are read into a single resolved declaration, so one constructor
     * parameter stands for the pair.
     */
    private const array FRESHNESS_KEYS_READ_INTO_ONE_DECLARATION = ['staleAfter', 'staleBehavior'];
    private const string RESOLVED_FRESHNESS_FIELD = 'staleDeclaration';

    public function testTheSchemaAndTheTrackerItemDeclareTheSameFields(): void
    {
        $fieldNamesFromSchema = self::withTheFreshnessKeysResolved(array_keys(self::trackerItemPropertiesDeclaredBySchema()));
        $fieldNamesFromTrackerItem = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            self::trackerItemConstructorParameters(),
        );

        sort($fieldNamesFromSchema);
        sort($fieldNamesFromTrackerItem);

        self::assertSame($fieldNamesFromTrackerItem, $fieldNamesFromSchema);
    }

    public function testTheDeviceContractBoundsTheTextFieldsToTheLengthsTheClientEnforces(): void
    {
        $trackerUpdateProperties = self::trackerUpdateRequestPropertiesDeclaredByDeviceContract();

        self::assertSame(TrackerPayload::MAXIMUM_SYMBOL_LENGTH, $trackerUpdateProperties['symbol']['maxLength'] ?? null);
        self::assertSame(TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH, $trackerUpdateProperties['bottomText']['maxLength'] ?? null);
    }

    public function testTheSchemaBoundsTheTextFieldsToTheLengthsTheDeviceAccepts(): void
    {
        $trackerItemProperties = self::trackerItemPropertiesDeclaredBySchema();

        self::assertSame(TrackerPayload::MAXIMUM_SYMBOL_LENGTH, $trackerItemProperties['label']['maxLength'] ?? null);
        self::assertSame(TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH, $trackerItemProperties['bottomText']['maxLength'] ?? null);
    }

    /**
     * @param list<string> $fieldNamesFromSchema
     *
     * @return list<string>
     */
    private static function withTheFreshnessKeysResolved(array $fieldNamesFromSchema): array
    {
        foreach (self::FRESHNESS_KEYS_READ_INTO_ONE_DECLARATION as $freshnessKey) {
            self::assertContains($freshnessKey, $fieldNamesFromSchema);
        }

        $resolvedFieldNames = array_values(array_diff($fieldNamesFromSchema, self::FRESHNESS_KEYS_READ_INTO_ONE_DECLARATION));
        $resolvedFieldNames[] = self::RESOLVED_FRESHNESS_FIELD;

        return $resolvedFieldNames;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function trackerItemPropertiesDeclaredBySchema(): array
    {
        $rawSchema = file_get_contents(SyncsConfigLoaderFactory::projectFilePath('pixelcast.schema.json'));
        self::assertIsString($rawSchema);

        $schema = json_decode($rawSchema, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        self::assertIsArray($schema['definitions']);
        self::assertIsArray($schema['definitions']['trackerItem']);
        self::assertIsArray($schema['definitions']['trackerItem']['properties']);

        return self::asPropertyMap($schema['definitions']['trackerItem']['properties']);
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function trackerUpdateRequestPropertiesDeclaredByDeviceContract(): array
    {
        $deviceContract = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath('sync/schemas/tracker.yaml'));
        self::assertIsArray($deviceContract);
        self::assertIsArray($deviceContract['TrackerUpdateRequest']);
        self::assertIsArray($deviceContract['TrackerUpdateRequest']['properties']);

        return self::asPropertyMap($deviceContract['TrackerUpdateRequest']['properties']);
    }

    /**
     * @param array<mixed> $properties
     *
     * @return array<string, array<mixed>>
     */
    private static function asPropertyMap(array $properties): array
    {
        $propertyMap = [];
        foreach ($properties as $fieldName => $fieldSchema) {
            self::assertIsArray($fieldSchema);
            $propertyMap[(string) $fieldName] = $fieldSchema;
        }

        return $propertyMap;
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
}
