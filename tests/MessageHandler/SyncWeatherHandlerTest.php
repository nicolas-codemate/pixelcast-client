<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Exception\DeviceBusyException;
use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Config\Exception\PixelCastConfigException;
use App\Message\SyncOutcome;
use App\Message\SyncWeatherMessage;
use App\MessageHandler\SyncWeatherHandler;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use App\Tests\Stub\StaticWeatherProviderStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class SyncWeatherHandlerTest extends TestCase
{
    public function testProviderPayloadIsPushedToTheDevice(): void
    {
        $payload = self::buildPayload();
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = new SyncWeatherHandler(new StaticWeatherProviderStub($payload), $client, $logger);

        $outcome = $handler(new SyncWeatherMessage());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame([$payload], $client->pushedPayloads);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(1, $logger->records[0]['context']['forecast_days'] ?? null);
    }

    public function testMissingProviderPayloadIsSkippedWithoutPush(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = new SyncWeatherHandler(new StaticWeatherProviderStub(), $client, $logger);

        $outcome = $handler(new SyncWeatherMessage());

        self::assertSame(SyncOutcome::Skipped, $outcome);
        self::assertSame([], $client->pushedPayloads);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
    }

    public function testDeviceFailureIsLoggedAndReportedAsFailed(): void
    {
        $deviceFailure = DeviceBusyException::slotExhausted('/weather');
        $client = new RecordingPixelcastClientStub($deviceFailure);
        $logger = new RecordingLoggerStub();
        $handler = new SyncWeatherHandler(new StaticWeatherProviderStub(self::buildPayload()), $client, $logger);

        $outcome = $handler(new SyncWeatherMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedPayloads);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($deviceFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testConfigFailureIsLoggedAndReportedAsFailed(): void
    {
        $configFailure = PixelCastConfigException::fileNotFound('/app/pixelcast.yaml');
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = new SyncWeatherHandler(new StaticWeatherProviderStub(failure: $configFailure), $client, $logger);

        $outcome = $handler(new SyncWeatherMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedPayloads);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($configFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    private static function buildPayload(): WeatherPayload
    {
        return new WeatherPayload(
            new CurrentWeather(WeatherIcon::PartlyDay, 25, 16, 27, 52),
            [new ForecastDay('LUN', WeatherIcon::PartlyDay, 16, 27)],
        );
    }
}
