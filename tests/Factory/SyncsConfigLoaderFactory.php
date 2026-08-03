<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Config\SyncsConfigLoader;

final class SyncsConfigLoaderFactory
{
    private const string SCHEMA_FILE_NAME = 'pixelcast.schema.json';

    public static function forConfigFile(string $configFilePath): SyncsConfigLoader
    {
        return new SyncsConfigLoader($configFilePath, self::projectFilePath(self::SCHEMA_FILE_NAME));
    }

    public static function projectFilePath(string $relativePath): string
    {
        return \dirname(__DIR__, 2).'/'.$relativePath;
    }
}
