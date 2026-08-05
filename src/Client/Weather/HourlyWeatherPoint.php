<?php

declare(strict_types=1);

namespace App\Client\Weather;

final readonly class HourlyWeatherPoint
{
    private const int MINIMUM_HOUR_OF_DAY = 0;
    private const int MAXIMUM_HOUR_OF_DAY = 23;
    private const int MINIMUM_TEMPERATURE = -100;
    private const int MAXIMUM_TEMPERATURE = 100;
    private const int MINIMUM_PRECIPITATION_PROBABILITY_PERCENTAGE = 0;
    private const int MAXIMUM_PRECIPITATION_PROBABILITY_PERCENTAGE = 100;
    private const int MINIMUM_PRECIPITATION_IN_TENTHS_OF_MILLIMETRE = 0;
    private const int MAXIMUM_PRECIPITATION_IN_TENTHS_OF_MILLIMETRE = 255;
    private const int TENTHS_OF_MILLIMETRE_PER_MILLIMETRE = 10;

    public function __construct(
        public int $hourOfDay,
        public int $temperature,
        public ?int $precipitationProbabilityPercentage = null,
        public ?int $precipitationInTenthsOfMillimetre = null,
    ) {
        self::assertWithinRange('Hour of day', $this->hourOfDay, self::MINIMUM_HOUR_OF_DAY, self::MAXIMUM_HOUR_OF_DAY);
        self::assertWithinRange('Temperature', $this->temperature, self::MINIMUM_TEMPERATURE, self::MAXIMUM_TEMPERATURE);
        self::assertWithinRange('Precipitation probability', $this->precipitationProbabilityPercentage, self::MINIMUM_PRECIPITATION_PROBABILITY_PERCENTAGE, self::MAXIMUM_PRECIPITATION_PROBABILITY_PERCENTAGE);
        self::assertWithinRange('Precipitation', $this->precipitationInTenthsOfMillimetre, self::MINIMUM_PRECIPITATION_IN_TENTHS_OF_MILLIMETRE, self::MAXIMUM_PRECIPITATION_IN_TENTHS_OF_MILLIMETRE);
    }

    /**
     * Providers report precipitation in millimetres, the device in tenths of a millimetre, and a downpour
     * can exceed the 25.5 mm the device can hold: every measurement is clamped rather than refused,
     * so a single extreme hour never costs the whole window.
     */
    public static function fromMeasurements(
        int $hourOfDay,
        float $temperature,
        ?float $precipitationProbabilityPercentage,
        ?float $precipitationInMillimetres,
    ): self {
        return new self(
            $hourOfDay,
            self::roundAndClamp($temperature, self::MINIMUM_TEMPERATURE, self::MAXIMUM_TEMPERATURE),
            self::roundAndClamp($precipitationProbabilityPercentage, self::MINIMUM_PRECIPITATION_PROBABILITY_PERCENTAGE, self::MAXIMUM_PRECIPITATION_PROBABILITY_PERCENTAGE),
            self::roundAndClamp(
                null === $precipitationInMillimetres ? null : $precipitationInMillimetres * self::TENTHS_OF_MILLIMETRE_PER_MILLIMETRE,
                self::MINIMUM_PRECIPITATION_IN_TENTHS_OF_MILLIMETRE,
                self::MAXIMUM_PRECIPITATION_IN_TENTHS_OF_MILLIMETRE,
            ),
        );
    }

    /**
     * @return array{h: int, temp: int, pop?: int, precip?: int}
     */
    public function toArray(): array
    {
        $payload = [
            'h' => $this->hourOfDay,
            'temp' => $this->temperature,
        ];

        if (null !== $this->precipitationProbabilityPercentage) {
            $payload['pop'] = $this->precipitationProbabilityPercentage;
        }

        if (null !== $this->precipitationInTenthsOfMillimetre) {
            $payload['precip'] = $this->precipitationInTenthsOfMillimetre;
        }

        return $payload;
    }

    private static function assertWithinRange(string $label, ?int $value, int $minimum, int $maximum): void
    {
        if (null === $value) {
            return;
        }

        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException(\sprintf('%s must be between %d and %d, got %d.', $label, $minimum, $maximum, $value));
        }
    }

    /**
     * @return ($value is null ? null : int)
     */
    private static function roundAndClamp(?float $value, int $minimum, int $maximum): ?int
    {
        if (null === $value) {
            return null;
        }

        return max($minimum, min($maximum, (int) round($value)));
    }
}
