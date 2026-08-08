<?php

declare(strict_types=1);

namespace App\Client\Weather;

use App\Client\StaleBehavior;

final readonly class WeatherPayload
{
    public const int MAXIMUM_HOURLY_WINDOW_POINTS = 12;

    private const int MAXIMUM_FORECAST_DAYS = 7;

    /**
     * @param list<ForecastDay> $forecastDays
     * @param list<HourlyWeatherPoint> $hourlyWindow
     */
    public function __construct(
        public CurrentWeather $current,
        public array $forecastDays = [],
        public array $hourlyWindow = [],
        public ?int $staleAfterInSeconds = null,
        public ?StaleBehavior $staleBehavior = null,
    ) {
        if (\count($this->forecastDays) > self::MAXIMUM_FORECAST_DAYS) {
            throw new \InvalidArgumentException(\sprintf('A weather payload carries at most %d forecast days, got %d.', self::MAXIMUM_FORECAST_DAYS, \count($this->forecastDays)));
        }

        if (\count($this->hourlyWindow) > self::MAXIMUM_HOURLY_WINDOW_POINTS) {
            throw new \InvalidArgumentException(\sprintf('A weather payload carries at most %d hourly points, got %d.', self::MAXIMUM_HOURLY_WINDOW_POINTS, \count($this->hourlyWindow)));
        }
    }

    /**
     * @return array{current: array{icon: string, temp: int, temp_min?: int, temp_max?: int, humidity?: int}, forecast?: list<array{day: string, icon: string, temp_min: int, temp_max: int}>, today?: array{hours: list<array{h: int, temp: int, pop?: int, precip?: int}>}, staleAfter?: int, staleBehavior?: string}
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

        if ([] !== $this->hourlyWindow) {
            $payload['today'] = [
                'hours' => array_map(
                    static fn (HourlyWeatherPoint $hourlyPoint): array => $hourlyPoint->toArray(),
                    $this->hourlyWindow,
                ),
            ];
        }

        if (null !== $this->staleAfterInSeconds) {
            $payload['staleAfter'] = $this->staleAfterInSeconds;
        }

        if (null !== $this->staleBehavior) {
            $payload['staleBehavior'] = $this->staleBehavior->value;
        }

        return $payload;
    }
}
