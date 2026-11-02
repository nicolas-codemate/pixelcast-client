<?php

declare(strict_types=1);

namespace App\Provider\HomeAssistant;

use App\Client\CustomApp\CustomAppPayload;
use App\Config\Exception\PixelCastConfigException;

interface HomeAssistantSensorProviderInterface
{
    /**
     * The custom app holding one zone per configured sensor, or null when no sensor could be read.
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchSensorsCustomApp(): ?CustomAppPayload;
}
