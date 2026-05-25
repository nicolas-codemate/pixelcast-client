<?php

declare(strict_types=1);

namespace App\Tui\Configuration;

final class ConfigurationFieldValidator
{
    public const string FIELD_DEVICE_URL = 'device_url';
    public const string FIELD_WEATHER_INTERVAL = 'weather_interval';
    public const string FIELD_TRACKER_INTERVAL = 'tracker_interval';
    public const string FIELD_TRACKED_ASSETS = 'tracked_assets';
    public const string FIELD_WEATHER_SOURCE = 'weather_source';
    public const string FIELD_TRACKER_SOURCE = 'tracker_source';

    public function validate(string $fieldId, string $rawValue): ?string
    {
        return match ($fieldId) {
            self::FIELD_DEVICE_URL => $this->validateDeviceUrl($rawValue),
            self::FIELD_WEATHER_INTERVAL => $this->validatePositiveInteger($rawValue, 'weather_interval'),
            self::FIELD_TRACKER_INTERVAL => $this->validatePositiveInteger($rawValue, 'tracker_interval'),
            self::FIELD_TRACKED_ASSETS => $this->validateTrackedAssets($rawValue),
            self::FIELD_WEATHER_SOURCE => $this->validateNonEmptyString($rawValue, 'weather_source'),
            self::FIELD_TRACKER_SOURCE => $this->validateNonEmptyString($rawValue, 'tracker_source'),
            default => \sprintf('Unknown field "%s".', $fieldId),
        };
    }

    private function validateDeviceUrl(string $rawValue): ?string
    {
        $trimmed = trim($rawValue);
        if ('' === $trimmed) {
            return 'device_url must not be empty.';
        }

        if (false === filter_var($trimmed, \FILTER_VALIDATE_URL)) {
            return 'device_url must be a valid URL.';
        }

        return null;
    }

    private function validatePositiveInteger(string $rawValue, string $fieldLabel): ?string
    {
        $trimmed = trim($rawValue);
        if ('' === $trimmed) {
            return \sprintf('%s must not be empty.', $fieldLabel);
        }

        if (!ctype_digit($trimmed) || (int) $trimmed <= 0) {
            return \sprintf('%s must be a positive integer.', $fieldLabel);
        }

        return null;
    }

    private function validateTrackedAssets(string $rawValue): ?string
    {
        foreach (explode(',', $rawValue) as $token) {
            if ('' !== trim($token)) {
                return null;
            }
        }

        return 'tracked_assets must contain at least one asset symbol.';
    }

    private function validateNonEmptyString(string $rawValue, string $fieldLabel): ?string
    {
        if ('' === trim($rawValue)) {
            return \sprintf('%s must not be empty.', $fieldLabel);
        }

        return null;
    }
}
