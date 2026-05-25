<?php

declare(strict_types=1);

namespace App\Tests\Tui\Configuration;

use App\Tui\Configuration\ConfigurationFieldValidator;
use PHPUnit\Framework\TestCase;

final class ConfigurationFieldValidatorTest extends TestCase
{
    private ConfigurationFieldValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigurationFieldValidator();
    }

    public function testDeviceUrlAcceptsValidUrl(): void
    {
        self::assertNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_DEVICE_URL,
            'http://pixelcast.local/api',
        ));
    }

    public function testDeviceUrlRejectsEmptyValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_DEVICE_URL,
            '',
        ));
    }

    public function testDeviceUrlRejectsWhitespaceOnlyValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_DEVICE_URL,
            '    ',
        ));
    }

    public function testDeviceUrlRejectsMalformedUrl(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_DEVICE_URL,
            'not-a-url',
        ));
    }

    public function testWeatherIntervalAcceptsPositiveInteger(): void
    {
        self::assertNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL,
            '300',
        ));
    }

    public function testWeatherIntervalRejectsZero(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL,
            '0',
        ));
    }

    public function testWeatherIntervalRejectsNegativeValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL,
            '-5',
        ));
    }

    public function testWeatherIntervalRejectsNonNumericValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL,
            '30s',
        ));
    }

    public function testWeatherIntervalRejectsEmptyValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL,
            '',
        ));
    }

    public function testTrackerIntervalAcceptsPositiveInteger(): void
    {
        self::assertNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKER_INTERVAL,
            '60',
        ));
    }

    public function testTrackerIntervalRejectsZero(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKER_INTERVAL,
            '0',
        ));
    }

    public function testTrackedAssetsAcceptsCommaSeparatedList(): void
    {
        self::assertNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKED_ASSETS,
            'BTC, AAPL, SPY',
        ));
    }

    public function testTrackedAssetsAcceptsSingleToken(): void
    {
        self::assertNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKED_ASSETS,
            'BTC',
        ));
    }

    public function testTrackedAssetsRejectsEmptyValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKED_ASSETS,
            '',
        ));
    }

    public function testTrackedAssetsRejectsOnlyCommasAndWhitespace(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKED_ASSETS,
            ' , ,  ,',
        ));
    }

    public function testWeatherSourceAcceptsNonEmptyValue(): void
    {
        self::assertNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_SOURCE,
            'openmeteo',
        ));
    }

    public function testWeatherSourceRejectsEmptyValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_SOURCE,
            '',
        ));
    }

    public function testWeatherSourceRejectsWhitespaceOnlyValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_WEATHER_SOURCE,
            "   \t",
        ));
    }

    public function testTrackerSourceAcceptsNonEmptyValue(): void
    {
        self::assertNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKER_SOURCE,
            'yahoo-finance',
        ));
    }

    public function testTrackerSourceRejectsEmptyValue(): void
    {
        self::assertNotNull($this->validator->validate(
            ConfigurationFieldValidator::FIELD_TRACKER_SOURCE,
            '',
        ));
    }

    public function testUnknownFieldReturnsError(): void
    {
        self::assertNotNull($this->validator->validate('unknown_field', 'value'));
    }
}
