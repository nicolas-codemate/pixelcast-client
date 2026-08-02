<?php

declare(strict_types=1);

namespace App\Client\Weather;

final readonly class CurrentWeather
{
    private const int MINIMUM_HUMIDITY_PERCENTAGE = 0;
    private const int MAXIMUM_HUMIDITY_PERCENTAGE = 100;

    public function __construct(
        public WeatherIcon $icon,
        public int $temperature,
        public ?int $minimumTemperature = null,
        public ?int $maximumTemperature = null,
        public ?int $humidityPercentage = null,
    ) {
        if (null === $this->humidityPercentage) {
            return;
        }

        if ($this->humidityPercentage < self::MINIMUM_HUMIDITY_PERCENTAGE || $this->humidityPercentage > self::MAXIMUM_HUMIDITY_PERCENTAGE) {
            throw new \InvalidArgumentException(\sprintf('Humidity must be between %d and %d, got %d.', self::MINIMUM_HUMIDITY_PERCENTAGE, self::MAXIMUM_HUMIDITY_PERCENTAGE, $this->humidityPercentage));
        }
    }

    /**
     * @return array{icon: string, temp: int, temp_min?: int, temp_max?: int, humidity?: int}
     */
    public function toArray(): array
    {
        $payload = [
            'icon' => $this->icon->value,
            'temp' => $this->temperature,
        ];

        if (null !== $this->minimumTemperature) {
            $payload['temp_min'] = $this->minimumTemperature;
        }

        if (null !== $this->maximumTemperature) {
            $payload['temp_max'] = $this->maximumTemperature;
        }

        if (null !== $this->humidityPercentage) {
            $payload['humidity'] = $this->humidityPercentage;
        }

        return $payload;
    }
}
