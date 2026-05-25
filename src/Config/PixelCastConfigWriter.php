<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Exception\PixelCastConfigException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class PixelCastConfigWriter
{
    private const string TOP_LEVEL_KEY_LINE_PATTERN = '/^(?<indent>\s*)(?<key>[A-Za-z0-9_]+)\s*:\s*(?<value>.*?)\s*$/';

    public function __construct(
        #[Autowire('%kernel.project_dir%/pixelcast.yaml')]
        private readonly string $configFilePath,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function save(PixelCastConfig $config): void
    {
        $desiredMap = $config->toRawMap();

        $newContent = $this->buildNewContent($desiredMap);

        $this->assertContentIsLoadable($newContent);

        $this->writeAtomically($newContent);
    }

    /**
     * @param array<string, scalar> $desiredMap
     */
    private function buildNewContent(array $desiredMap): string
    {
        if (!is_file($this->configFilePath)) {
            return Yaml::dump($desiredMap, 2);
        }

        $originalContent = file_get_contents($this->configFilePath);
        if (false === $originalContent) {
            throw PixelCastConfigException::writeFailed($this->configFilePath, new \RuntimeException(\sprintf('Failed to read "%s" before rewriting.', $this->configFilePath)));
        }

        $originalLines = preg_split('/(?<=\n)/', $originalContent);
        if (false === $originalLines) {
            throw PixelCastConfigException::writeFailed($this->configFilePath, new \RuntimeException(\sprintf('Failed to split "%s" into lines.', $this->configFilePath)));
        }
        if ('' === ($originalLines[\count($originalLines) - 1] ?? '')) {
            array_pop($originalLines);
        }

        $existingValues = $this->parseExistingValues($originalContent);

        $pendingMap = $desiredMap;
        $rewrittenLines = [];

        foreach ($originalLines as $line) {
            $lineEnding = str_ends_with($line, "\n") ? "\n" : '';
            $stripped = '' === $lineEnding ? $line : substr($line, 0, -1);

            if (1 === preg_match(self::TOP_LEVEL_KEY_LINE_PATTERN, $stripped, $matches)) {
                $key = $matches['key'];
                if (\array_key_exists($key, $pendingMap)) {
                    $desiredValue = $pendingMap[$key];
                    $valueAlreadyMatches = \array_key_exists($key, $existingValues)
                        && $existingValues[$key] === $desiredValue;

                    if (!$valueAlreadyMatches) {
                        $line = \sprintf('%s%s: %s', $matches['indent'], $key, $this->formatScalar($desiredValue)).$lineEnding;
                    }

                    unset($pendingMap[$key]);
                    $rewrittenLines[] = $line;

                    continue;
                }
            }

            $rewrittenLines[] = $line;
        }

        $buffer = implode('', $rewrittenLines);

        if ([] !== $pendingMap) {
            if (!str_ends_with($buffer, "\n\n")) {
                if (!str_ends_with($buffer, "\n")) {
                    $buffer .= "\n";
                }
                $buffer .= "\n";
            }

            foreach ($pendingMap as $key => $value) {
                $buffer .= \sprintf("%s: %s\n", $key, $this->formatScalar($value));
            }
        }

        return $this->normalizeTrailingNewline($buffer);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseExistingValues(string $content): array
    {
        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException) {
            return [];
        }

        if (!\is_array($parsed)) {
            return [];
        }

        $stringKeyed = [];
        foreach ($parsed as $key => $value) {
            if (\is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }

        return $stringKeyed;
    }

    private function normalizeTrailingNewline(string $buffer): string
    {
        $trimmed = rtrim($buffer, "\n");
        if ('' === $trimmed) {
            return "\n";
        }

        return $trimmed."\n";
    }

    private function formatScalar(mixed $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_string($value)) {
            $dumped = Yaml::dump($value, 0, 2, Yaml::DUMP_NULL_AS_TILDE);

            return rtrim($dumped, "\n");
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        throw PixelCastConfigException::invalidValue('<scalar>', \sprintf('unsupported scalar type "%s"', get_debug_type($value)));
    }

    private function assertContentIsLoadable(string $buffer): void
    {
        try {
            $parsed = Yaml::parse($buffer);
        } catch (ParseException $parseError) {
            throw PixelCastConfigException::invalidYaml($this->configFilePath, $parseError);
        }

        PixelCastConfig::fromArray(
            PixelCastConfig::asStringKeyedMap($parsed, $this->configFilePath),
        );
    }

    private function writeAtomically(string $buffer): void
    {
        $temporaryPath = $this->configFilePath.'.tmp';

        try {
            $bytesWritten = @file_put_contents($temporaryPath, $buffer);
            if (false === $bytesWritten) {
                throw new \RuntimeException(\sprintf('Failed to write temporary file "%s".', $temporaryPath));
            }

            $this->filesystem->rename($temporaryPath, $this->configFilePath, true);
        } catch (IOException|\RuntimeException $writeError) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw PixelCastConfigException::writeFailed($this->configFilePath, $writeError);
        }
    }
}
