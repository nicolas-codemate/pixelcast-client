<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads field declarations out of the schema documents the client is bounded by: pixelcast.schema.json
 * for its own configuration, and the device contract sync/schemas/ copies from the firmware repository.
 */
final class SchemaPropertyReader extends Assert
{
    private const string DEVICE_CONTRACT_DIRECTORY = 'sync/schemas';
    private const string SHARED_DEFINITIONS_FILE = 'common.yaml';

    /**
     * @return array<string, array<mixed>>
     */
    public static function deviceContractPropertiesOf(string $contractFileName, string $definitionName): array
    {
        $deviceContract = Yaml::parseFile(self::deviceContractFilePath($contractFileName));
        self::assertIsArray($deviceContract);
        self::assertArrayHasKey($definitionName, $deviceContract);
        self::assertIsArray($deviceContract[$definitionName]);
        self::assertIsArray($deviceContract[$definitionName]['properties']);

        return self::asPropertyMap($deviceContract[$definitionName]['properties']);
    }

    /**
     * @param array<mixed> $properties
     *
     * @return array<string, array<mixed>>
     */
    public static function asPropertyMap(array $properties): array
    {
        $propertyMap = [];
        foreach ($properties as $fieldName => $fieldSchema) {
            self::assertIsArray($fieldSchema);
            $propertyMap[(string) $fieldName] = $fieldSchema;
        }

        return $propertyMap;
    }

    /**
     * A field the device accepts as a plain string, a colored string or colored segments points at
     * a shared definition holding one branch per form, each with the bound that form is measured
     * by. The character bound is therefore read from the plain string branch.
     *
     * @param array<mixed> $fieldSchema
     */
    public static function plainStringLengthBoundOf(array $fieldSchema): int
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
     * The colored segments branch is the only form measured in segments, so the segment cap is read
     * from it the way the character bound is read from the plain string branch.
     *
     * @param array<mixed> $fieldSchema
     */
    public static function segmentCountBoundOf(array $fieldSchema): int
    {
        $acceptedForms = self::acceptedFormsOfPolymorphicTextField($fieldSchema);

        foreach ($acceptedForms as $acceptedForm) {
            if (!\is_array($acceptedForm) || 'array' !== ($acceptedForm['type'] ?? null)) {
                continue;
            }

            $segmentBound = $acceptedForm['maxItems'] ?? null;
            self::assertIsInt($segmentBound);

            return $segmentBound;
        }

        self::fail('The polymorphic text field declares no colored segments form.');
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

        $sharedDefinitions = Yaml::parseFile(self::deviceContractFilePath(self::SHARED_DEFINITIONS_FILE));
        self::assertIsArray($sharedDefinitions);
        self::assertArrayHasKey($definitionName, $sharedDefinitions);
        self::assertIsArray($sharedDefinitions[$definitionName]);
        self::assertIsArray($sharedDefinitions[$definitionName]['oneOf']);

        return $sharedDefinitions[$definitionName]['oneOf'];
    }

    private static function deviceContractFilePath(string $contractFileName): string
    {
        return SyncsConfigLoaderFactory::projectFilePath(self::DEVICE_CONTRACT_DIRECTORY.'/'.$contractFileName);
    }
}
