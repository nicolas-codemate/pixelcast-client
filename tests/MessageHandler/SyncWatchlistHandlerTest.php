<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Exception\DeviceBusyException;
use App\Client\Watchlist\WatchlistPayload;
use App\Client\Watchlist\WatchlistRow;
use App\Config\Exception\PixelCastConfigException;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncWatchlistMessage;
use App\MessageHandler\SyncWatchlistHandler;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use App\Tests\Stub\StaticWatchlistProviderStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncWatchlistHandlerTest extends TestCase
{
    private const string PUSH_INSTANT = '2026-11-13 16:00:00';
    private const string SYNC_TYPE = 'watchlist';
    private const string WATCHLIST_PATH = '/watchlist';

    public function testTheQuotesArePushedAsAWatchlistAndRecordASuccess(): void
    {
        $watchlist = self::buildWatchlist();
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticWatchlistProviderStub($watchlist), $lastSuccessfulSyncStore, $client, $logger);

        $outcome = $handler(new SyncWatchlistMessage());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame([$watchlist], $client->pushedWatchlists);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(2, $logger->records[0]['context']['row_count'] ?? null);
        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testAnUnreadableQuoteIsAFailureThatLeavesTheDeviceUntouched(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(new StaticWatchlistProviderStub(), $lastSuccessfulSyncStore, $client, $logger);

        $outcome = $handler(new SyncWatchlistMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedWatchlists);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testADeviceFailureIsReportedAsFailedWithoutRethrowing(): void
    {
        $deviceFailure = DeviceBusyException::slotExhausted(self::WATCHLIST_PATH);
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            new StaticWatchlistProviderStub(self::buildWatchlist()),
            $lastSuccessfulSyncStore,
            new RecordingPixelcastClientStub($deviceFailure),
            $logger,
        );

        $outcome = $handler(new SyncWatchlistMessage());

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
            new StaticWatchlistProviderStub(failure: $configFailure),
            self::buildStore(),
            new RecordingPixelcastClientStub(),
            $logger,
        );

        $outcome = $handler(new SyncWatchlistMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame($configFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    private static function buildHandler(
        StaticWatchlistProviderStub $watchlistProvider,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore,
        RecordingPixelcastClientStub $client = new RecordingPixelcastClientStub(),
        RecordingLoggerStub $logger = new RecordingLoggerStub(),
    ): SyncWatchlistHandler {
        return new SyncWatchlistHandler($watchlistProvider, $client, $logger, $lastSuccessfulSyncStore);
    }

    private static function buildStore(): LastSuccessfulSyncStore
    {
        return new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock(self::PUSH_INSTANT));
    }

    private static function buildWatchlist(): WatchlistPayload
    {
        return WatchlistPayload::create(
            name: 'watchlist',
            rows: [
                WatchlistRow::create('SpaceX', 184.2, 3.42, currency: 'USD'),
                WatchlistRow::create('Meta', 612.85, -1.08, currency: 'USD'),
            ],
        );
    }
}
