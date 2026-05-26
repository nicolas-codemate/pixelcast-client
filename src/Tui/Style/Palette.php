<?php

declare(strict_types=1);

namespace App\Tui\Style;

final readonly class Palette
{
    public function __construct(
        public string $headerText = 'cyan',
        public string $devChipBackground = 'yellow',
        public string $devChipForeground = 'black',
        public string $prodChipBackground = 'red',
        public string $prodChipForeground = 'white',
        public string $borderDim = 'gray',
        public string $borderAccent = 'green',
        public string $dimText = 'gray',
        public string $accentText = 'bright_white',
    ) {
    }
}
