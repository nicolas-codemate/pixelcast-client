<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Color;
use App\Client\CustomApp\CustomAppPayload;
use App\Client\Exception\DeviceBusyException;
use App\Client\Exception\ResourceNotFoundException;
use App\Config\Exception\PixelCastConfigException;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncGitHubMessage;
use App\Message\SyncOutcome;
use App\MessageHandler\SyncGitHubHandler;
use App\Provider\GitHub\PullRequestCountDisplay;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tests\Stub\RecordingPixelcastClientStub;
use App\Tests\Stub\StaticGitHubPullRequestProviderStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SyncGitHubHandlerTest extends TestCase
{
    private const string PUSH_INSTANT = '2026-08-13 10:00:00';
    private const string SYNC_TYPE = 'github';
    private const string CUSTOM_APP_NAME = 'github';
    private const string CUSTOM_APP_PATH = '/custom';

    public function testACountIsPushedAsACustomApp(): void
    {
        $customApp = self::buildCustomApp();
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(self::providerShowing($customApp), self::buildStore(), $client, $logger);

        $outcome = $handler(new SyncGitHubMessage());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame([$customApp], $client->pushedCustomApps);
        self::assertSame([], $client->deletedCustomAppNames);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(self::CUSTOM_APP_NAME, $logger->records[0]['context']['custom_app'] ?? null);
    }

    public function testAnEmptyQueueRemovesTheAppFromTheDevice(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(self::providerRemovingTheApp(), self::buildStore(), $client, $logger);

        $outcome = $handler(new SyncGitHubMessage());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame([self::CUSTOM_APP_NAME], $client->deletedCustomAppNames);
        self::assertSame([], $client->pushedCustomApps);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
    }

    public function testAnAppThatWasNeverPushedIsNotAFailedCycle(): void
    {
        $client = new RecordingPixelcastClientStub(ResourceNotFoundException::forPath(self::CUSTOM_APP_PATH));
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(self::providerRemovingTheApp(), $lastSuccessfulSyncStore, $client, $logger);

        $outcome = $handler(new SyncGitHubMessage());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame([LogLevel::INFO], array_column($logger->records, 'level'));
        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testAnEmptyQueueRecordsASuccessSoThatAQuietWeekDoesNotLookStale(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(self::providerRemovingTheApp(), $lastSuccessfulSyncStore);

        $handler(new SyncGitHubMessage());

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testAnUnreadableCountIsSkippedWithoutTouchingTheDevice(): void
    {
        $client = new RecordingPixelcastClientStub();
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            new StaticGitHubPullRequestProviderStub(PullRequestCountDisplay::couldNotBeRead()),
            $lastSuccessfulSyncStore,
            $client,
            $logger,
        );

        $outcome = $handler(new SyncGitHubMessage());

        self::assertSame(SyncOutcome::Skipped, $outcome);
        self::assertSame([], $client->pushedCustomApps);
        self::assertSame([], $client->deletedCustomAppNames);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testADeviceFailureOnTheDeletionIsReportedAsFailedWithoutRethrowing(): void
    {
        $deviceFailure = DeviceBusyException::slotExhausted(self::CUSTOM_APP_PATH);
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(
            self::providerRemovingTheApp(),
            $lastSuccessfulSyncStore,
            new RecordingPixelcastClientStub($deviceFailure),
            $logger,
        );

        $outcome = $handler(new SyncGitHubMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($deviceFailure, $logger->records[0]['context']['exception'] ?? null);
        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testADeviceFailureOnThePushIsReportedAsFailedWithoutRethrowing(): void
    {
        $deviceFailure = DeviceBusyException::slotExhausted(self::CUSTOM_APP_PATH);
        $client = new RecordingPixelcastClientStub($deviceFailure);
        $logger = new RecordingLoggerStub();
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(self::providerShowing(self::buildCustomApp()), $lastSuccessfulSyncStore, $client, $logger);

        $outcome = $handler(new SyncGitHubMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame([], $client->pushedCustomApps);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($deviceFailure, $logger->records[0]['context']['exception'] ?? null);
        self::assertNull($lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    public function testAProviderFailureIsLoggedAndReportedAsFailed(): void
    {
        $configFailure = PixelCastConfigException::fileNotFound('/app/pixelcast.yaml');
        $logger = new RecordingLoggerStub();
        $handler = self::buildHandler(
            new StaticGitHubPullRequestProviderStub(failure: $configFailure),
            self::buildStore(),
            new RecordingPixelcastClientStub(),
            $logger,
        );

        $outcome = $handler(new SyncGitHubMessage());

        self::assertSame(SyncOutcome::Failed, $outcome);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level'] ?? null);
        self::assertSame($configFailure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testASuccessfulPushRecordsItsInstant(): void
    {
        $lastSuccessfulSyncStore = self::buildStore();
        $handler = self::buildHandler(self::providerShowing(self::buildCustomApp()), $lastSuccessfulSyncStore);

        $handler(new SyncGitHubMessage());

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(self::SYNC_TYPE));
    }

    private static function buildHandler(
        StaticGitHubPullRequestProviderStub $gitHubPullRequestProvider,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore,
        RecordingPixelcastClientStub $client = new RecordingPixelcastClientStub(),
        RecordingLoggerStub $logger = new RecordingLoggerStub(),
    ): SyncGitHubHandler {
        return new SyncGitHubHandler($gitHubPullRequestProvider, $client, $logger, $lastSuccessfulSyncStore);
    }

    private static function providerShowing(CustomAppPayload $customApp): StaticGitHubPullRequestProviderStub
    {
        return new StaticGitHubPullRequestProviderStub(PullRequestCountDisplay::showsCount($customApp));
    }

    private static function providerRemovingTheApp(): StaticGitHubPullRequestProviderStub
    {
        return new StaticGitHubPullRequestProviderStub(PullRequestCountDisplay::removesTheApp(self::CUSTOM_APP_NAME));
    }

    private static function buildStore(): LastSuccessfulSyncStore
    {
        return new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock(self::PUSH_INSTANT));
    }

    private static function buildCustomApp(): CustomAppPayload
    {
        return CustomAppPayload::createSingleZone(
            name: self::CUSTOM_APP_NAME,
            text: '3',
            iconName: 'github',
            label: 'A relire',
            color: Color::fromHexCode('#8957E5'),
        );
    }
}
