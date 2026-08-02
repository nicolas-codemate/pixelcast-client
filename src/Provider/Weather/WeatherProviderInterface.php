<?php

declare(strict_types=1);

namespace App\Provider\Weather;

use App\Client\Weather\WeatherPayload;
use App\Config\Exception\PixelCastConfigException;

interface WeatherProviderInterface
{
    /**
     * @return WeatherPayload|null null when the upstream weather API is unreachable or returns an unusable response
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchWeather(): ?WeatherPayload;
}
