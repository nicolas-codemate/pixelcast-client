<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Exception\PixelCastConfigException;

final readonly class PixelCastConfig
{
    private const string KEY_DEVICE_URL = 'device_url';
    private const string KEY_WEATHER_INTERVAL = 'weather_interval';
    private const string KEY_TRACKER_INTERVAL = 'tracker_interval';
    private const string KEY_TRACKED_ASSETS = 'tracked_assets';
    private const string KEY_WEATHER_SOURCE = 'weather_source';
    private const string KEY_TRACKER_SOURCE = 'tracker_source';

    /**
     * @param list<string> $trackedAssets
     */
    public function __construct(
        public string $deviceUrl,
        public int $weatherInterval,
        public int $trackerInterval,
        public array $trackedAssets,
        public string $weatherSource,
        public string $trackerSource,
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
        $deviceUrl = self::requireString($raw, self::KEY_DEVICE_URL);
        $weatherInterval = self::requirePositiveInt($raw, self::KEY_WEATHER_INTERVAL);
        $trackerInterval = self::requirePositiveInt($raw, self::KEY_TRACKER_INTERVAL);
        $trackedAssetsRaw = self::requireString($raw, self::KEY_TRACKED_ASSETS);
        $weatherSource = self::requireString($raw, self::KEY_WEATHER_SOURCE);
        $trackerSource = self::requireString($raw, self::KEY_TRACKER_SOURCE);

        return new self(
            $deviceUrl,
            $weatherInterval,
            $trackerInterval,
            self::splitTrackedAssets($trackedAssetsRaw),
            $weatherSource,
            $trackerSource,
        );
    }

    /**
     * @return array<string, scalar>
     */
    public function toRawMap(): array
    {
        return [
            self::KEY_DEVICE_URL => $this->deviceUrl,
            self::KEY_WEATHER_INTERVAL => $this->weatherInterval,
            self::KEY_TRACKER_INTERVAL => $this->trackerInterval,
            self::KEY_TRACKED_ASSETS => implode(', ', $this->trackedAssets),
            self::KEY_WEATHER_SOURCE => $this->weatherSource,
            self::KEY_TRACKER_SOURCE => $this->trackerSource,
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
