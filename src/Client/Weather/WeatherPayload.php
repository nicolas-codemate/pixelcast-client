<?php

declare(strict_types=1);

namespace App\Client\Weather;

final readonly class WeatherPayload
{
    private const int MAXIMUM_FORECAST_DAYS = 7;

    /**
     * @param list<ForecastDay> $forecastDays
     */
    public function __construct(
        public CurrentWeather $current,
        public array $forecastDays = [],
    ) {
        if (\count($this->forecastDays) > self::MAXIMUM_FORECAST_DAYS) {
            throw new \InvalidArgumentException(\sprintf('A weather payload carries at most %d forecast days, got %d.', self::MAXIMUM_FORECAST_DAYS, \count($this->forecastDays)));
        }
    }

    /**
     * @return array{current: array{icon: string, temp: int, temp_min?: int, temp_max?: int, humidity?: int}, forecast?: list<array{day: string, icon: string, temp_min: int, temp_max: int}>}
     */
    public function toArray(): array
    {
        $payload = ['current' => $this->current->toArray()];

        if ([] !== $this->forecastDays) {
            $payload['forecast'] = array_map(
                static fn (ForecastDay $forecastDay): array => $forecastDay->toArray(),
                $this->forecastDays,
            );
        }

        return $payload;
    }
}
