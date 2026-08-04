<?php

declare(strict_types=1);

namespace App\Client\Exception;

final class DeviceUnreachableException extends \RuntimeException implements PixelcastClientException
{
    public static function forPath(string $path, \Throwable $previous): self
    {
        return new self(
            \sprintf('Device unreachable while calling "%s": %s', $path, $previous->getMessage()),
            0,
            $previous,
        );
    }

    // An unmapped status tells us nothing about whether the push went through, just like no answer at all.
    public static function unexpectedStatus(string $path, int $httpStatus, string $responseBody): self
    {
        return new self(\sprintf('Device answered an unmapped HTTP %d for "%s": %s', $httpStatus, $path, $responseBody));
    }
}
