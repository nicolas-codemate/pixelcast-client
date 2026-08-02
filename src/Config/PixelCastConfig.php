<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Exception\PixelCastConfigException;

final readonly class PixelCastConfig
{
    private const string KEY_WEATHER_INTERVAL = 'weather_interval';
    private const string KEY_TRACKER_INTERVAL = 'tracker_interval';
    private const string KEY_TRACKED_ASSETS = 'tracked_assets';
    private const string KEY_WEATHER_SOURCE = 'weather_source';
    private const string KEY_TRACKER_SOURCE = 'tracker_source';
    private const string KEY_WEATHER_LATITUDE = 'weather_latitude';
    private const string KEY_WEATHER_LONGITUDE = 'weather_longitude';
    private const string KEY_WEATHER_UNITS = 'weather_units';
    private const string KEY_WEATHER_LOCALE = 'weather_locale';

    private const float MINIMUM_LATITUDE = -90.0;
    private const float MAXIMUM_LATITUDE = 90.0;
    private const float MINIMUM_LONGITUDE = -180.0;
    private const float MAXIMUM_LONGITUDE = 180.0;

    /**
     * @param list<string> $trackedAssets
     */
    public function __construct(
        public int $weatherInterval,
        public int $trackerInterval,
        public array $trackedAssets,
        public string $weatherSource,
        public string $trackerSource,
        public float $weatherLatitude,
        public float $weatherLongitude,
        public WeatherUnits $weatherUnits,
        public WeatherLocale $weatherLocale,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function asStringKeyedMap(mixed $parsed, string $contextLabel): array
    {
        if (!\is_array($parsed)) {
            throw PixelCastConfigException::invalidYaml($contextLabel, new \RuntimeException('top-level YAML must be a key-value map'));
        }

        $stringKeyed = [];
        foreach ($parsed as $key => $value) {
            if (!\is_string($key)) {
                throw PixelCastConfigException::invalidYaml($contextLabel, new \RuntimeException('top-level YAML keys must be strings'));
            }
            $stringKeyed[$key] = $value;
        }

        return $stringKeyed;
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $weatherInterval = self::requirePositiveInt($raw, self::KEY_WEATHER_INTERVAL);
        $trackerInterval = self::requirePositiveInt($raw, self::KEY_TRACKER_INTERVAL);
        $trackedAssetsRaw = self::requireString($raw, self::KEY_TRACKED_ASSETS);
        $weatherSource = self::requireString($raw, self::KEY_WEATHER_SOURCE);
        $trackerSource = self::requireString($raw, self::KEY_TRACKER_SOURCE);
        $weatherLatitude = self::requireBoundedFloat($raw, self::KEY_WEATHER_LATITUDE, self::MINIMUM_LATITUDE, self::MAXIMUM_LATITUDE);
        $weatherLongitude = self::requireBoundedFloat($raw, self::KEY_WEATHER_LONGITUDE, self::MINIMUM_LONGITUDE, self::MAXIMUM_LONGITUDE);
        $weatherUnits = self::requireEnum($raw, self::KEY_WEATHER_UNITS, WeatherUnits::class);
        $weatherLocale = self::requireEnum($raw, self::KEY_WEATHER_LOCALE, WeatherLocale::class);

        return new self(
            $weatherInterval,
            $trackerInterval,
            self::splitTrackedAssets($trackedAssetsRaw),
            $weatherSource,
            $trackerSource,
            $weatherLatitude,
            $weatherLongitude,
            $weatherUnits,
            $weatherLocale,
        );
    }

    /**
     * @return array<string, scalar>
     */
    public function toRawMap(): array
    {
        return [
            self::KEY_WEATHER_INTERVAL => $this->weatherInterval,
            self::KEY_TRACKER_INTERVAL => $this->trackerInterval,
            self::KEY_TRACKED_ASSETS => implode(', ', $this->trackedAssets),
            self::KEY_WEATHER_SOURCE => $this->weatherSource,
            self::KEY_TRACKER_SOURCE => $this->trackerSource,
            self::KEY_WEATHER_LATITUDE => $this->weatherLatitude,
            self::KEY_WEATHER_LONGITUDE => $this->weatherLongitude,
            self::KEY_WEATHER_UNITS => $this->weatherUnits->value,
            self::KEY_WEATHER_LOCALE => $this->weatherLocale->value,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireString(array $raw, string $key): string
    {
        if (!\array_key_exists($key, $raw)) {
            throw PixelCastConfigException::missingKey($key);
        }

        $value = $raw[$key];
        if (!\is_string($value)) {
            throw PixelCastConfigException::invalidValue($key, 'expected string');
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            throw PixelCastConfigException::invalidValue($key, 'must not be empty');
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requirePositiveInt(array $raw, string $key): int
    {
        if (!\array_key_exists($key, $raw)) {
            throw PixelCastConfigException::missingKey($key);
        }

        $value = $raw[$key];
        if (!\is_int($value) || $value <= 0) {
            throw PixelCastConfigException::invalidValue($key, 'expected positive integer');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireBoundedFloat(array $raw, string $key, float $minimum, float $maximum): float
    {
        if (!\array_key_exists($key, $raw)) {
            throw PixelCastConfigException::missingKey($key);
        }

        $value = $raw[$key];
        if (!\is_float($value) && !\is_int($value)) {
            throw PixelCastConfigException::invalidValue($key, 'expected number');
        }

        $asFloat = (float) $value;
        if ($asFloat < $minimum || $asFloat > $maximum) {
            throw PixelCastConfigException::invalidValue($key, \sprintf('expected a number between %s and %s', $minimum, $maximum));
        }

        return $asFloat;
    }

    /**
     * @template TBackedEnum of \BackedEnum
     *
     * @param array<string, mixed> $raw
     * @param class-string<TBackedEnum> $enumClass
     *
     * @return TBackedEnum
     */
    private static function requireEnum(array $raw, string $key, string $enumClass): \BackedEnum
    {
        $matchedCase = $enumClass::tryFrom(self::requireString($raw, $key));

        if (null === $matchedCase) {
            throw PixelCastConfigException::invalidValue($key, \sprintf('expected one of: %s', implode(', ', array_column($enumClass::cases(), 'value'))));
        }

        return $matchedCase;
    }

    /**
     * @return list<string>
     */
    private static function splitTrackedAssets(string $raw): array
    {
        $tokens = explode(',', $raw);
        $cleaned = [];
        foreach ($tokens as $token) {
            $trimmed = trim($token);
            if ('' !== $trimmed) {
                $cleaned[] = $trimmed;
            }
        }

        return $cleaned;
    }
}
