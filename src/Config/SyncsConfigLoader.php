<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sleep\DeviceSleepConfig;
use App\Config\Sync\SyncGroupRegistry;
use App\Config\Sync\SyncOptionReader;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class SyncsConfigLoader
{
    private ?SyncsConfig $loadedConfig = null;

    public function __construct(
        #[Autowire('%app.config.file%')]
        private readonly string $configFilePath,
        #[Autowire('%kernel.project_dir%/pixelcast.schema.json')]
        private readonly string $schemaFilePath,
    ) {
    }

    /**
     * The file is read once: editing it on the host only takes effect when the process restarts.
     */
    public function load(): SyncsConfig
    {
        return $this->loadedConfig ??= $this->readConfigFile();
    }

    private function readConfigFile(): SyncsConfig
    {
        if (!is_file($this->configFilePath)) {
            throw PixelCastConfigException::fileNotFound($this->configFilePath);
        }

        try {
            // The validator expects stdClass maps, which is also what tells an empty map from an empty list.
            $parsedTree = Yaml::parseFile($this->configFilePath, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $parseError) {
            throw PixelCastConfigException::invalidYaml($this->configFilePath, $parseError);
        }

        $this->validateAgainstSchema($parsedTree);

        $configTree = $this->associativeTree($parsedTree);

        $syncGroups = [];
        foreach (self::syncGroupOptions($configTree) as $syncType => $options) {
            $syncGroups[$syncType] = SyncGroupRegistry::syncGroupClassFor($syncType)::fromOptions($options);
        }

        return new SyncsConfig($syncGroups, DeviceSleepConfig::optionalFromConfigTree($configTree));
    }

    private function validateAgainstSchema(mixed $parsedTree): void
    {
        $validator = new Validator();
        $validator->validate($parsedTree, $this->readSchemaFile());

        if ($validator->isValid()) {
            return;
        }

        $violations = [];
        foreach ($validator->getErrors() as $error) {
            $violations[] = self::describeViolation($error);
        }

        throw PixelCastConfigException::schemaViolations($this->configFilePath, $violations);
    }

    private function readSchemaFile(): \stdClass
    {
        if (!is_file($this->schemaFilePath)) {
            throw PixelCastConfigException::schemaNotReadable($this->schemaFilePath, 'file not found');
        }

        $rawSchema = file_get_contents($this->schemaFilePath);
        if (false === $rawSchema) {
            throw PixelCastConfigException::schemaNotReadable($this->schemaFilePath, 'file could not be read');
        }

        try {
            $schemaTree = json_decode($rawSchema, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $decodeError) {
            throw PixelCastConfigException::schemaNotReadable($this->schemaFilePath, $decodeError->getMessage());
        }

        if (!$schemaTree instanceof \stdClass) {
            throw PixelCastConfigException::schemaNotReadable($this->schemaFilePath, 'expected a JSON object');
        }

        return $schemaTree;
    }

    private static function describeViolation(mixed $error): string
    {
        // getErrors() is untyped in the vendor library, hence the guard.
        if (!\is_array($error)) {
            return '<root>: invalid value';
        }

        $property = $error['property'] ?? null;
        $message = $error['message'] ?? null;

        return \sprintf(
            '%s: %s',
            \is_string($property) && '' !== $property ? $property : '<root>',
            \is_string($message) ? $message : 'invalid value',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function associativeTree(mixed $parsedTree): array
    {
        try {
            $associativeTree = json_decode(json_encode($parsedTree, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $conversionError) {
            throw PixelCastConfigException::invalidYaml($this->configFilePath, $conversionError);
        }

        return SyncOptionReader::asStringKeyedMap($associativeTree, '<root>');
    }

    /**
     * @param array<string, mixed> $configTree
     *
     * @return array<string, array<string, mixed>>
     */
    private static function syncGroupOptions(array $configTree): array
    {
        $syncGroupOptions = [];
        foreach (SyncOptionReader::asStringKeyedMap($configTree['syncs'] ?? null, 'syncs') as $syncType => $options) {
            $syncGroupOptions[$syncType] = SyncOptionReader::asStringKeyedMap($options, 'syncs.'.$syncType);
        }

        return $syncGroupOptions;
    }
}
