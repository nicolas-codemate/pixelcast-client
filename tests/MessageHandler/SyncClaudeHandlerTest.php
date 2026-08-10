<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Exception\DeviceBusyException;
use App\Client\Gauge\GaugePayload;
use App\Client\Gauge\GaugeRow;
use App\Config\Exception\PixelCastConfigException;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncClaudeMessage;
use App\Message\SyncOutcome;
use App\MessageHandler\SyncClaudeHandler;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use App\Tests\Stub\StaticClaudeUsageProviderStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncClaudeHandlerTest extends TestCase
{
    private const string PUSH_INSTANT = '2026-08-03 10:00:00';

    public function testProviderPayloadIsPushedToTheDevice(): void
    {
        $gauge = self::buildGauge();
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(new StaticClaudeUsageProviderStub($gauge), self::buildStore(), $client, $logger);

        $outcome = $handler(new SyncClaudeMessage());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame([$gauge], $client->pushedGauges);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(2, $logger->records[0]['context']['rows'] ?? null);
    }

    public function testMissingProviderPayloadIsSkippedWithoutPush(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(new StaticClaudeUsageProviderStub(), self::buildStore(), $client, $logger);

        $outcome = $handler(new SyncClaudeMessage());

        self::assertSame(SyncOutcome::Skipped, $outcome);
        self::assertSame([], $client->pushedGauges);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
    }

    public function testDeviceFailureIsLoggedAndReportedAsFailedWithoutRethrowing(): void
    {
        $deviceFailure = DeviceBusyException::slotExhausted('/gauge');
        $client = new RecordingPixelcastClientStub($deviceFailure);
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(new StaticClaudeUsageProviderStub(self::buildGauge()), self::buildStore(), $client, $logger);

        $outcome = $handler(new SyncClaudeMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedGauges);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($deviceFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testConfigFailureIsLoggedAndReportedAsFailed(): void
    {
        $configFailure = PixelCastConfigException::fileNotFound('/app/pixelcast.yaml');
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            new StaticClaudeUsageProviderStub(failure: $configFailure),
            self::buildStore(),
            new RecordingPixelcastClientStub(),
            $logger,
        );

        $outcome = $handler(new SyncClaudeMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($configFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testASuccessfulPushRecordsItsInstant(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticClaudeUsageProviderStub(self::buildGauge()), $lastSuccessfulSyncStore);

        $handler(new SyncClaudeMessage());

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf('claude'));
    }

    public function testASkippedCycleRecordsNoSuccessSoThatStalenessCanDoItsJob(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticClaudeUsageProviderStub(), $lastSuccessfulSyncStore);

        $handler(new SyncClaudeMessage());

        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf('claude'));
    }

    public function testAFailedCycleRecordsNoSuccess(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            new StaticClaudeUsageProviderStub(self::buildGauge()),
            $lastSuccessfulSyncStore,
            new RecordingPixelcastClientStub(DeviceBusyException::slotExhausted('/gauge')),
        );

        $handler(new SyncClaudeMessage());

        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf('claude'));
    }

    private static function buildHandler(
        StaticClaudeUsageProviderStub $claudeUsageProvider,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore,
        RecordingPixelcastClientStub $client = new RecordingPixelcastClientStub(),
        RecordingLoggerStub $logger = new RecordingLoggerStub(),
    ): SyncClaudeHandler {
        return new SyncClaudeHandler($claudeUsageProvider, $client, $logger, $lastSuccessfulSyncStore);
    }

    private static function buildStore(): LastSuccessfulSyncStore
    {
        return new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock(self::PUSH_INSTANT));
    }

    private static function buildGauge(): GaugePayload
    {
        return GaugePayload::create(
            name: 'claude',
            rows: [
                GaugeRow::create(label: '5h', percent: 41, value: '41%'),
                GaugeRow::create(label: '7j', percent: 28, value: '28%'),
            ],
            title: 'Claude',
        );
    }
}
