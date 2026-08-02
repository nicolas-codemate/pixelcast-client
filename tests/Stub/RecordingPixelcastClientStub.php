<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\PixelcastClientInterface;
use App\Client\Weather\WeatherPayload;

final class RecordingPixelcastClientStub implements PixelcastClientInterface
{
    /**
     * @var list<WeatherPayload>
     */
    public array $pushedPayloads = [];

    public function __construct(
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function pushWeather(WeatherPayload $weather): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->pushedPayloads[] = $weather;
    }
}
