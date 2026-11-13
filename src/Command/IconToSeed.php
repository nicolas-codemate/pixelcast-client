<?php

declare(strict_types=1);

namespace App\Command;

/**
 * An icon pixelcast.yaml asks the device for, with the LaMetric icon it can be downloaded from and
 * the item that asks for it, named in the report of app:icons:seed.
 */
final readonly class IconToSeed
{
    public function __construct(
        public ?int $lametricId,
        public string $requestedBy,
    ) {
    }
}
