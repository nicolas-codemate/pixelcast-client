<?php

declare(strict_types=1);

namespace App\Tui;

enum TuiMode: string
{
    case Dev = 'dev';
    case Prod = 'prod';

    public static function fromAppEnvironment(string $appEnvironment): self
    {
        return 'dev' === $appEnvironment ? self::Dev : self::Prod;
    }

    public function displayLabel(): string
    {
        return self::Dev === $this ? 'DEV' : 'PROD';
    }
}
