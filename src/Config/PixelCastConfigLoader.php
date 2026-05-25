<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Exception\PixelCastConfigException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class PixelCastConfigLoader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/pixelcast.yaml')]
        private string $configFilePath,
    ) {
    }

    public function load(): PixelCastConfig
    {
        if (!$this->exists()) {
            throw PixelCastConfigException::fileNotFound($this->configFilePath);
        }

        try {
            $parsed = Yaml::parseFile($this->configFilePath);
        } catch (ParseException $parseError) {
            throw PixelCastConfigException::invalidYaml($this->configFilePath, $parseError);
        }

        return PixelCastConfig::fromArray(
            PixelCastConfig::asStringKeyedMap($parsed, $this->configFilePath),
        );
    }

    public function exists(): bool
    {
        return is_file($this->configFilePath);
    }

    public function filePath(): string
    {
        return $this->configFilePath;
    }
}
