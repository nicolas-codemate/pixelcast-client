<?php

declare(strict_types=1);

namespace App\Claude\Exception;

final class ClaudeCredentialsException extends \RuntimeException
{
    public static function fileNotFound(string $credentialsFilePath): self
    {
        return new self(\sprintf('No Claude credentials file at "%s".', $credentialsFilePath));
    }

    public static function fileNotReadable(string $credentialsFilePath): self
    {
        return new self(\sprintf('The Claude credentials file at "%s" could not be read.', $credentialsFilePath));
    }

    public static function malformedJson(string $credentialsFilePath, \Throwable $previous): self
    {
        return new self(
            \sprintf('The Claude credentials file at "%s" is not valid JSON: %s', $credentialsFilePath, $previous->getMessage()),
            0,
            $previous,
        );
    }

    public static function unsupportedVersion(string $credentialsFilePath, mixed $declaredVersion, int $supportedVersion): self
    {
        return new self(\sprintf(
            'The Claude credentials file at "%s" declares version %s, and only version %d can be read.',
            $credentialsFilePath,
            \is_int($declaredVersion) ? (string) $declaredVersion : get_debug_type($declaredVersion),
            $supportedVersion,
        ));
    }

    public static function malformedField(string $credentialsFilePath, string $fieldPath): self
    {
        return new self(\sprintf('The Claude credentials file at "%s" carries no usable "%s".', $credentialsFilePath, $fieldPath));
    }

    public static function directoryNotWritable(string $directoryPath): self
    {
        return new self(\sprintf('The Claude credentials directory "%s" could not be created.', $directoryPath));
    }

    public static function writeFailed(string $credentialsFilePath, string $reason): self
    {
        return new self(\sprintf('The Claude credentials file at "%s" could not be written: %s', $credentialsFilePath, $reason));
    }
}
