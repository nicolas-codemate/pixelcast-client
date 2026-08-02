<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\Weather\WeatherPayload;
use App\Provider\Weather\WeatherProviderInterface;

final class StaticWeatherProviderStub implements WeatherProviderInterface
{
    public function __construct(
        private readonly ?WeatherPayload $payload = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function fetchWeather(): ?WeatherPayload
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->payload;
    }
}
