<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

final class OpenApiSpecNotFoundException extends \RuntimeException
{
    public static function forPath(string $specPath): self
    {
        return new self(\sprintf('OpenAPI spec file not found at "%s".', $specPath));
    }
}
