<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\CustomApp\CustomAppPayload;
use App\Client\CustomApp\Zone;
use App\Client\Exception\DeviceBusyException;
use App\Config\Exception\PixelCastConfigException;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncHomeAssistantMessage;
use App\Message\SyncOutcome;
use App\MessageHandler\SyncHomeAssistantHandler;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use App\Tests\Stub\StaticHomeAssistantSensorProviderStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncHomeAssistantHandlerTest extends TestCase
{
    private const string PUSH_INSTANT = '2026-11-02 10:00:00';
    private const string SYNC_TYPE = 'homeassistant';
    private const string CUSTOM_APP_NAME = 'homeassistant';
    private const string CUSTOM_APP_PATH = '/custom';

    public function testTheSensorsArePushedAsACustomAppAndRecordASuccess(): void
    {
        $customApp = self::buildCustomApp();
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticHomeAssistantSensorProviderStub($customApp), $lastSuccessfulSyncStore, $client, $logger);

        $outcome = $handler(new SyncHomeAssistantMessage());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame([$customApp], $client->pushedCustomApps);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(self::CUSTOM_APP_NAME, $logger->records[0]['context']['custom_app'] ?? null);
        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testNoReadableSensorIsSkippedWithoutTouchingTheDevice(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticHomeAssistantSensorProviderStub(), $lastSuccessfulSyncStore, $client, $logger);

        $outcome = $handler(new SyncHomeAssistantMessage());

        self::assertSame(SyncOutcome::Skipped, $outcome);
        self::assertSame([], $client->pushedCustomApps);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testADeviceFailureIsReportedAsFailedWithoutRethrowing(): void
    {
        $deviceFailure = DeviceBusyException::slotExhausted(self::CUSTOM_APP_PATH);
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            new StaticHomeAssistantSensorProviderStub(self::buildCustomApp()),
            $lastSuccessfulSyncStore,
            new RecordingPixelcastClientStub($deviceFailure),
            $logger,
        );

        $outcome = $handler(new SyncHomeAssistantMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($deviceFailure, $logger->records[0]['context']['exception'] ?? null);
        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testAProviderFailureIsLoggedAndReportedAsFailed(): void
    {
        $configFailure = PixelCastConfigException::fileNotFound('/app/pixelcast.yaml');
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            new StaticHomeAssistantSensorProviderStub(failure: $configFailure),
            self::buildStore(),
            new RecordingPixelcastClientStub(),
            $logger,
        );

        $outcome = $handler(new SyncHomeAssistantMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($configFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    private static function buildHandler(
        StaticHomeAssistantSensorProviderStub $homeAssistantSensorProvider,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore,
        RecordingPixelcastClientStub $client = new RecordingPixelcastClientStub(),
        RecordingLoggerStub $logger = new RecordingLoggerStub(),
    ): SyncHomeAssistantHandler {
        return new SyncHomeAssistantHandler($homeAssistantSensorProvider, $client, $logger, $lastSuccessfulSyncStore);
    }

    private static function buildStore(): LastSuccessfulSyncStore
    {
        return new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock(self::PUSH_INSTANT));
    }

    private static function buildCustomApp(): CustomAppPayload
    {
        return CustomAppPayload::createMultiZone(
            name: self::CUSTOM_APP_NAME,
            zones: [
                Zone::create(text: '21.3°', label: 'Salon'),
                Zone::create(text: '8.7°', label: 'Dehors'),
            ],
        );
    }
}
