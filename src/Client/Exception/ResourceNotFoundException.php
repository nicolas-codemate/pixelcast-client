<?php

declare(strict_types=1);

namespace App\Client\Exception;

final class ResourceNotFoundException extends \RuntimeException implements PixelcastClientException
{
    public static function forPath(string $path): self
    {
        return new self(\sprintf('Device answered HTTP 404 for "%s".', $path));
    }
}
