<?php

declare(strict_types=1);

namespace App\Tui\Menu;

final readonly class TuiMenuItem
{
    public function __construct(
        public string $shortcut,
        public string $label,
        public string $value,
    ) {
    }
}
