<?php

declare(strict_types=1);

namespace App\Client;

use App\Client\Exception\DeviceBusyException;
use App\Client\Exception\DeviceUnreachableException;
use App\Client\Exception\InvalidPayloadException;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Weather\WeatherPayload;

interface PixelcastClientInterface
{
    /**
     * @throws InvalidPayloadException the payload does not match the device spec (local check or HTTP 400)
     * @throws ResourceNotFoundException the endpoint is missing on the device (HTTP 404)
     * @throws DeviceBusyException the device cannot accept the push right now (HTTP 500 or 503)
     * @throws DeviceUnreachableException the device did not answer, or answered an unmapped status
     */
    public function pushWeather(WeatherPayload $weather): void;
}
