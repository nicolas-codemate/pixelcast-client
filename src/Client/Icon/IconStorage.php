<?php

declare(strict_types=1);

namespace App\Client\Icon;

/**
 * How full the device filesystem is, which is what tells whether another icon still fits.
 */
final readonly class IconStorage
{
    public function __construct(
        public int $usedBytes,
        public int $totalBytes,
    ) {
    }

    public function availableBytes(): int
    {
        return max(0, $this->totalBytes - $this->usedBytes);
    }
}
