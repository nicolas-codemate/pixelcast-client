<?php

declare(strict_types=1);

namespace App\Client\Exception;

final class InvalidPayloadException extends \RuntimeException implements PixelcastClientException
{
    public static function fromLocalValidation(string $path, string $errorMessage): self
    {
        return new self(\sprintf('Payload rejected before sending to "%s": %s', $path, $errorMessage));
    }

    public static function fromDeviceResponse(string $path, string $deviceMessage): self
    {
        return new self(\sprintf('Device rejected the request sent to "%s" (HTTP 400): %s', $path, $deviceMessage));
    }
}
