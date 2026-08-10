<?php

declare(strict_types=1);

namespace App\Provider\Claude;

use App\Client\Gauge\GaugePayload;
use App\Config\Exception\PixelCastConfigException;

interface ClaudeUsageProviderInterface
{
    /**
     * @return GaugePayload|null null when the session cannot be used, the usage endpoint is
     *                           unreachable, or nothing in its answer can be drawn as a row
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchUsageGauge(): ?GaugePayload;
}
