<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Validation;

use App\Simulator\Validation\OpenApiValidatorFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class OpenApiValidatorFactoryTest extends TestCase
{
    private string $temporaryDirectory;
    private string $specFilePath;
    private string $referencedSchemaFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-openapi-cache-'.bin2hex(random_bytes(6));
        $this->specFilePath = $this->temporaryDirectory.'/openapi.yaml';
        $this->referencedSchemaFilePath = $this->temporaryDirectory.'/schemas/tracker.yaml';

        new Filesystem()->dumpFile($this->specFilePath, self::specReferencingItsSchemaDirectory());
        new Filesystem()->dumpFile($this->referencedSchemaFilePath, self::trackerSchema('integer'));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testBuildingTheSameSpecTwiceReusesTheCachedSchema(): void
    {
        $cache = new ArrayAdapter();
        $factory = new OpenApiValidatorFactory($cache);

        $factory->create($this->specFilePath);
        $factory->create($this->specFilePath);

        self::assertCount(1, $cache->getValues());
    }

    public function testEditingAReferencedSchemaFileInPlaceInvalidatesTheCachedSchema(): void
    {
        $cache = new ArrayAdapter();
        $factory = new OpenApiValidatorFactory($cache);

        $factory->create($this->specFilePath);
        new Filesystem()->dumpFile($this->referencedSchemaFilePath, self::trackerSchema('string'));
        $factory->create($this->specFilePath);

        self::assertCount(2, $cache->getValues());
    }

    private static function specReferencingItsSchemaDirectory(): string
    {
        return <<<'YAML'
            openapi: 3.0.3
            info:
              title: Cache invalidation fixture
              version: 1.0.0
            paths:
              /trackers:
                get:
                  responses:
                    '200':
                      description: A tracker
                      content:
                        application/json:
                          schema:
                            $ref: './schemas/tracker.yaml#/Tracker'
            YAML;
    }

    private static function trackerSchema(string $valueType): string
    {
        return <<<YAML
            Tracker:
              type: object
              properties:
                value:
                  type: {$valueType}
            YAML;
    }
}
