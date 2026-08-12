<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\SyncGroupRegistry;
use App\Config\Sync\TrackerItem;
use App\Config\Sync\TrackerSyncConfig;
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
        self::assertSame(
            TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH,
            self::plainStringLengthBoundOf($trackerUpdateProperties['bottomText']),
        );
    }

    public function testTheDeviceContractBoundsBothSeriesToTheChartColumnsTheClientFillsThem(): void
    {
        $trackerUpdateProperties = self::trackerUpdateRequestPropertiesDeclaredByDeviceContract();

        self::assertSame(TrackerPayload::MAXIMUM_SPARKLINE_POINTS, $trackerUpdateProperties['sparkline']['maxItems'] ?? null);
        self::assertSame(TrackerPayload::MAXIMUM_SPARKLINE_POINTS, $trackerUpdateProperties['volumes']['maxItems'] ?? null);
    }

    public function testTheSchemaBoundsTheTextFieldsToTheLengthsTheDeviceAccepts(): void
    {
        $trackerItemProperties = self::trackerItemPropertiesDeclaredBySchema();

        self::assertSame(TrackerPayload::MAXIMUM_SYMBOL_LENGTH, $trackerItemProperties['label']['maxLength'] ?? null);
        self::assertSame(TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH, $trackerItemProperties['bottomText']['maxLength'] ?? null);
    }

    /**
     * The schema lists every bottom line a tracker item may carry, whatever its group, and each
     * group narrows that list to what it can serve.
     */
    public function testEveryGroupOnlyAcceptsBottomLinesTheSchemaDeclares(): void
    {
        $bottomLineValuesFromSchema = self::trackerItemPropertiesDeclaredBySchema()['bottomLine']['enum'] ?? null;
        self::assertIsArray($bottomLineValuesFromSchema);

        $trackerSyncGroupClasses = self::trackerSyncGroupClasses();
        self::assertNotSame([], $trackerSyncGroupClasses);

        foreach ($trackerSyncGroupClasses as $trackerSyncGroupClass) {
            foreach ($trackerSyncGroupClass::acceptedBottomLines() as $acceptedBottomLine) {
                self::assertContains($acceptedBottomLine->value, $bottomLineValuesFromSchema);
            }
        }
    }

    /**
     * @return list<class-string<TrackerSyncConfig>>
     */
    private static function trackerSyncGroupClasses(): array
    {
        $trackerSyncGroupClasses = [];

        foreach (SyncGroupRegistry::syncTypes() as $syncType) {
            $syncGroupClass = SyncGroupRegistry::syncGroupClassFor($syncType);

            if (is_a($syncGroupClass, TrackerSyncConfig::class, true)) {
                $trackerSyncGroupClasses[] = $syncGroupClass;
            }
        }

        return $trackerSyncGroupClasses;
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
     * A field the device accepts as a plain string, a colored string or colored segments points at
     * a shared definition holding one branch per form, each with the bound that form is measured
     * by. The character bound is therefore read from the plain string branch.
     *
     * @param array<mixed> $fieldSchema
     */
    private static function plainStringLengthBoundOf(array $fieldSchema): int
    {
        $acceptedForms = self::acceptedFormsOfPolymorphicTextField($fieldSchema);

        foreach ($acceptedForms as $acceptedForm) {
            if (!\is_array($acceptedForm) || 'string' !== ($acceptedForm['type'] ?? null)) {
                continue;
            }

            $lengthBound = $acceptedForm['maxLength'] ?? null;
            self::assertIsInt($lengthBound);

            return $lengthBound;
        }

        self::fail('The polymorphic text field declares no plain string form.');
    }

    /**
     * @param array<mixed> $fieldSchema
     *
     * @return array<mixed>
     */
    private static function acceptedFormsOfPolymorphicTextField(array $fieldSchema): array
    {
        $referencedDefinitions = $fieldSchema['allOf'] ?? null;
        self::assertIsArray($referencedDefinitions);
        self::assertIsArray($referencedDefinitions[0]);

        $definitionReference = $referencedDefinitions[0]['$ref'] ?? null;
        self::assertIsString($definitionReference);

        $definitionName = substr($definitionReference, (int) strpos($definitionReference, '#/') + 2);

        $commonDefinitions = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath('sync/schemas/common.yaml'));
        self::assertIsArray($commonDefinitions);
        self::assertArrayHasKey($definitionName, $commonDefinitions);
        self::assertIsArray($commonDefinitions[$definitionName]);
        self::assertIsArray($commonDefinitions[$definitionName]['oneOf']);

        return $commonDefinitions[$definitionName]['oneOf'];
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
