<?php

declare(strict_types=1);

namespace App\Client\Exception;

final class DeviceBusyException extends \RuntimeException implements PixelcastClientException
{
    private function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }

    public static function slotExhausted(string $path): self
    {
        return new self(500, \sprintf('Device answered HTTP 500 for "%s": no free application slot.', $path));
    }

    public static function queueFull(string $path): self
    {
        return new self(503, \sprintf('Device answered HTTP 503 for "%s": request queue is full.', $path));
    }
}
