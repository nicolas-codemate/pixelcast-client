<?php

declare(strict_types=1);

namespace App\Client\Weather;

final readonly class ForecastDay
{
    private const int DAY_LABEL_LENGTH = 3;

    public function __construct(
        public string $dayLabel,
        public WeatherIcon $icon,
        public int $minimumTemperature,
        public int $maximumTemperature,
    ) {
        // The spec only caps the label at 3 characters, but the firmware renders a fixed 3-character column.
        if (self::DAY_LABEL_LENGTH !== mb_strlen($this->dayLabel)) {
            throw new \InvalidArgumentException(\sprintf('Forecast day label must be exactly %d characters, got "%s".', self::DAY_LABEL_LENGTH, $this->dayLabel));
        }
    }

    /**
     * @return array{day: string, icon: string, temp_min: int, temp_max: int}
     */
    public function toArray(): array
    {
        return [
            'day' => $this->dayLabel,
            'icon' => $this->icon->value,
            'temp_min' => $this->minimumTemperature,
            'temp_max' => $this->maximumTemperature,
        ];
    }
}
