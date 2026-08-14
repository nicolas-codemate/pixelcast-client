<?php

declare(strict_types=1);

namespace App\Tests\Client\Settings;

use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\NtpSettings;
use App\Client\Settings\SettingsPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsPayloadTest extends TestCase
{
    public function testToArrayEmitsEveryFieldWhenProvided(): void
    {
        $settings = SettingsPayload::create(
            brightness: BrightnessLevel::create(200),
            autoRotate: true,
            defaultDurationMilliseconds: 8000,
            weatherDurationMilliseconds: 12000,
            ntp: NtpSettings::create(server: 'pool.ntp.org', timezonePosix: 'UTC0'),
        );

        self::assertSame(
            [
                'brightness' => 200,
                'autoRotate' => true,
                'defaultDuration' => 8000,
                'weatherDuration' => 12000,
                'ntp' => ['server' => 'pool.ntp.org', 'tz_posix' => 'UTC0'],
            ],
            $settings->toArray(),
        );
    }

    public function testToArrayOmitsTheFieldsLeftNull(): void
    {
        $settings = SettingsPayload::create(defaultDurationMilliseconds: 5000);

        $payload = $settings->toArray();

        self::assertSame(['defaultDuration' => 5000], $payload);
        self::assertArrayNotHasKey('brightness', $payload);
        self::assertArrayNotHasKey('autoRotate', $payload);
        self::assertArrayNotHasKey('weatherDuration', $payload);
        self::assertArrayNotHasKey('ntp', $payload);
    }

    public function testToArrayKeepsADisabledAutoRotate(): void
    {
        $settings = SettingsPayload::create(autoRotate: false);

        self::assertSame(['autoRotate' => false], $settings->toArray());
    }

    public function testToArrayKeepsAZeroBrightness(): void
    {
        $settings = SettingsPayload::create(brightness: BrightnessLevel::create(0));

        self::assertSame(['brightness' => 0], $settings->toArray());
    }

    public function testCreateRejectsAnUpdateCarryingNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A settings update must carry at least one field.');

        SettingsPayload::create();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideBoundaryWeatherDurationCases(): iterable
    {
        yield 'lower bound' => [3000];
        yield 'upper bound' => [60000];
    }

    #[DataProvider('provideBoundaryWeatherDurationCases')]
    public function testCreateAcceptsTheWeatherDurationBounds(int $weatherDurationMilliseconds): void
    {
        $settings = SettingsPayload::create(weatherDurationMilliseconds: $weatherDurationMilliseconds);

        self::assertSame(['weatherDuration' => $weatherDurationMilliseconds], $settings->toArray());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideOutOfRangeWeatherDurationCases(): iterable
    {
        yield 'just below the lower bound' => [2999];
        yield 'just above the upper bound' => [60001];
    }

    #[DataProvider('provideOutOfRangeWeatherDurationCases')]
    public function testCreateRejectsAnOutOfRangeWeatherDuration(int $weatherDurationMilliseconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The weather duration must be between 3000 and 60000 milliseconds');

        SettingsPayload::create(weatherDurationMilliseconds: $weatherDurationMilliseconds);
    }

    public function testCreateLeavesTheDefaultDurationUnbounded(): void
    {
        $settings = SettingsPayload::create(defaultDurationMilliseconds: 1);

        self::assertSame(['defaultDuration' => 1], $settings->toArray());
    }
}
