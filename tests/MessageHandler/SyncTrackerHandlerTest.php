<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Exception\InvalidPayloadException;
use App\Client\Tracker\TrackerPayload;
use App\Config\Exception\PixelCastConfigException;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\MessageHandler\SyncTrackerHandler;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use App\Tests\Stub\StaticTrackerProviderStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncTrackerHandlerTest extends TestCase
{
    private const string PUSH_INSTANT = '2026-08-04 10:00:00';
    private const string COINGECKO_SYNC_TYPE = 'coingecko';
    private const string TWELVEDATA_SYNC_TYPE = 'twelvedata';

    public function testEveryProviderPayloadIsPushedToTheDevice(): void
    {
        $trackerPayloads = [self::buildTrackerPayload('BTC'), self::buildTrackerPayload('ETH')];
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE, $trackerPayloads)],
            self::buildStore(),
            $client,
            $logger,
        );

        $outcome = $handler(new SyncTrackerMessage(self::COINGECKO_SYNC_TYPE));

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame($trackerPayloads, $client->pushedTrackers);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(2, $logger->records[0]['context']['tracker_count'] ?? null);
    }

    public function testTheProviderIsPickedFromTheSyncTypeOfTheMessage(): void
    {
        $twelveDataPayloads = [self::buildTrackerPayload('CW8')];
        $client = new RecordingPixelcastClientStub();
        $handler = self::buildHandler(
            [
                new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE, [self::buildTrackerPayload('BTC')]),
                new StaticTrackerProviderStub(self::TWELVEDATA_SYNC_TYPE, $twelveDataPayloads),
            ],
            self::buildStore(),
            $client,
        );

        $outcome = $handler(new SyncTrackerMessage(self::TWELVEDATA_SYNC_TYPE));

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame($twelveDataPayloads, $client->pushedTrackers);
    }

    public function testAnUnservedSyncTypeIsReportedAsFailed(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE, [self::buildTrackerPayload('BTC')])],
            self::buildStore(),
            $client,
            $logger,
        );

        $outcome = $handler(new SyncTrackerMessage(self::TWELVEDATA_SYNC_TYPE));

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedTrackers);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame(self::TWELVEDATA_SYNC_TYPE, $logger->records[0]['context']['sync_type'] ?? null);
    }

    public function testAnEmptyTrackerListIsSkippedWithoutPush(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE)],
            self::buildStore(),
            $client,
            $logger,
        );

        $outcome = $handler(new SyncTrackerMessage(self::COINGECKO_SYNC_TYPE));

        self::assertSame(SyncOutcome::Skipped, $outcome);
        self::assertSame([], $client->pushedTrackers);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
    }

    public function testAProviderFailureIsLoggedAndReportedAsFailed(): void
    {
        $configFailure = PixelCastConfigException::fileNotFound('/app/pixelcast.yaml');
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE, failure: $configFailure)],
            self::buildStore(),
            $client,
            $logger,
        );

        $outcome = $handler(new SyncTrackerMessage(self::COINGECKO_SYNC_TYPE));

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedTrackers);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($configFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testARejectedTrackerDoesNotHoldBackTheOthers(): void
    {
        $bitcoinPayload = self::buildTrackerPayload('BTC');
        $ethereumPayload = self::buildTrackerPayload('ETH');
        $rejection = InvalidPayloadException::fromDeviceResponse('/tracker', 'symbol is too long');
        $client = new RecordingPixelcastClientStub(trackerFailures: ['BTC' => $rejection]);
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE, [$bitcoinPayload, $ethereumPayload])],
            self::buildStore(),
            $client,
            $logger,
        );

        $outcome = $handler(new SyncTrackerMessage(self::COINGECKO_SYNC_TYPE));

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([$ethereumPayload], $client->pushedTrackers);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame('BTC', $logger->records[0]['context']['tracker_name'] ?? null);
        self::assertSame($rejection, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testASuccessfulCycleRecordsItsInstantUnderTheSyncType(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE, [self::buildTrackerPayload('BTC')])],
            $lastSuccessfulSyncStore,
        );

        $handler(new SyncTrackerMessage(self::COINGECKO_SYNC_TYPE));

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(self::COINGECKO_SYNC_TYPE));
    }

    public function testAPartiallyFailedCycleRecordsNoSuccess(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $rejection = InvalidPayloadException::fromDeviceResponse('/tracker', 'symbol is too long');
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE, [self::buildTrackerPayload('BTC'), self::buildTrackerPayload('ETH')])],
            $lastSuccessfulSyncStore,
            new RecordingPixelcastClientStub(trackerFailures: ['BTC' => $rejection]),
        );

        $handler(new SyncTrackerMessage(self::COINGECKO_SYNC_TYPE));

        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::COINGECKO_SYNC_TYPE));
    }

    public function testASkippedCycleRecordsNoSuccess(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            [new StaticTrackerProviderStub(self::COINGECKO_SYNC_TYPE)],
            $lastSuccessfulSyncStore,
        );

        $handler(new SyncTrackerMessage(self::COINGECKO_SYNC_TYPE));

        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::COINGECKO_SYNC_TYPE));
    }

    /**
     * @param list<StaticTrackerProviderStub> $trackerProviders
     */
    private static function buildHandler(
        array $trackerProviders,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore,
        RecordingPixelcastClientStub $client = new RecordingPixelcastClientStub(),
        RecordingLoggerStub $logger = new RecordingLoggerStub(),
    ): SyncTrackerHandler {
        return new SyncTrackerHandler($trackerProviders, $client, $logger, $lastSuccessfulSyncStore);
    }

    private static function buildStore(): LastSuccessfulSyncStore
    {
        return new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock(self::PUSH_INSTANT));
    }

    private static function buildTrackerPayload(string $name): TrackerPayload
    {
        return new TrackerPayload(
            name: $name,
            symbol: $name,
            currency: 'EUR',
            currentValue: 45450.53,
            changePercentage: 2.47,
        );
    }
}
