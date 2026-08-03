<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Exception\DeviceBusyException;
use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Config\Exception\PixelCastConfigException;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncWeatherMessage;
use App\MessageHandler\SyncWeatherHandler;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use App\Tests\Stub\StaticWeatherProviderStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncWeatherHandlerTest extends TestCase
{
    private const string PUSH_INSTANT = '2026-08-03 10:00:00';

    public function testProviderPayloadIsPushedToTheDevice(): void
    {
        $payload = self::buildPayload();
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(new StaticWeatherProviderStub($payload), self::buildStore(), $client, $logger);

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
        $handler = self::buildHandler(new StaticWeatherProviderStub(), self::buildStore(), $client, $logger);

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
        $handler = self::buildHandler(new StaticWeatherProviderStub(self::buildPayload()), self::buildStore(), $client, $logger);

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
        $handler = self::buildHandler(new StaticWeatherProviderStub(failure: $configFailure), self::buildStore(), $client, $logger);

        $outcome = $handler(new SyncWeatherMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedPayloads);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($configFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testASuccessfulPushRecordsItsInstant(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticWeatherProviderStub(self::buildPayload()), $lastSuccessfulSyncStore);

        $handler(new SyncWeatherMessage());

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf('weather'));
    }

    public function testASkippedCycleRecordsNoSuccess(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticWeatherProviderStub(), $lastSuccessfulSyncStore);

        $handler(new SyncWeatherMessage());

        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf('weather'));
    }

    public function testAFailedCycleRecordsNoSuccess(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            new StaticWeatherProviderStub(self::buildPayload()),
            $lastSuccessfulSyncStore,
            new RecordingPixelcastClientStub(DeviceBusyException::slotExhausted('/weather')),
        );

        $handler(new SyncWeatherMessage());

        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf('weather'));
    }

    private static function buildHandler(
        StaticWeatherProviderStub $weatherProvider,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore,
        RecordingPixelcastClientStub $client = new RecordingPixelcastClientStub(),
        RecordingLoggerStub $logger = new RecordingLoggerStub(),
    ): SyncWeatherHandler {
        return new SyncWeatherHandler($weatherProvider, $client, $logger, $lastSuccessfulSyncStore);
    }

    private static function buildStore(): LastSuccessfulSyncStore
    {
        return new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock(self::PUSH_INSTANT));
    }

    private static function buildPayload(): WeatherPayload
    {
        return new WeatherPayload(
            new CurrentWeather(WeatherIcon::PartlyDay, 25, 16, 27, 52),
            [new ForecastDay('LUN', WeatherIcon::PartlyDay, 16, 27)],
        );
    }
}
