<?php

declare(strict_types=1);

namespace App\Client\Icon;

final readonly class IconInfo
{
    public function __construct(
        public string $name,
        public string $fileName,
        public int $sizeInBytes,
    ) {
    }
}
