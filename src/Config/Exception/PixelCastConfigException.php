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

    public static function missingKey(string $key): self
    {
        return new self(\sprintf('Missing required PixelCast config key "%s".', $key));
    }

    public static function invalidValue(string $key, string $reason): self
    {
        return new self(\sprintf('Invalid value for PixelCast config key "%s": %s', $key, $reason));
    }

    public static function writeFailed(string $filePath, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to write PixelCast config to "%s": %s', $filePath, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
