<?php

declare(strict_types=1);

namespace App\Config\Exception;

final class PixelCastConfigException extends \RuntimeException
{
    public static function fileNotFound(string $filePath): self
    {
        return new self(\sprintf('PixelCast config file not found at "%s".', $filePath));
    }

    public static function invalidYaml(string $filePath, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to parse PixelCast config at "%s": %s', $filePath, $previous->getMessage()),
            0,
            $previous,
        );
    }

    /**
     * @param list<string> $violations each violation names the faulty key with its full path, e.g. syncs.coingecko.items[0]
     */
    public static function schemaViolations(string $filePath, array $violations): self
    {
        return new self(\sprintf(
            "Invalid PixelCast config at \"%s\":\n  - %s",
            $filePath,
            implode("\n  - ", $violations),
        ));
    }

    public static function schemaNotReadable(string $schemaFilePath, string $reason): self
    {
        return new self(\sprintf('Failed to read the PixelCast config schema at "%s": %s', $schemaFilePath, $reason));
    }

    public static function missingKey(string $key): self
    {
        return new self(\sprintf('Missing required PixelCast config key "%s".', $key));
    }

    /**
     * A key that is declared either where it is used or once for the whole device, and that is
     * declared in neither place.
     */
    public static function missingKeyOrDeviceDefault(string $key, string $deviceKey, string $reason): self
    {
        return new self(\sprintf(
            'Missing required PixelCast config key "%s": %s Declare it there, or once for the whole device under "%s".',
            $key,
            $reason,
            $deviceKey,
        ));
    }

    public static function invalidValue(string $key, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Invalid value for PixelCast config key "%s": %s', $key, $reason),
            0,
            $previous,
        );
    }
}
