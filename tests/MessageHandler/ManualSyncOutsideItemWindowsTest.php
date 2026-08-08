<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\BoursoramaSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\MessageHandler\SyncTrackerHandler;
use App\Provider\Tracker\BoursoramaTrackerProvider;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingPixelcastClientStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `app:sync <type>` is the diagnostic command: dispatched by hand it must push the whole group,
 * even outside the hours its items declare, or testing the device in the evening becomes
 * impossible. A scheduled cycle keeps spending the provider quota on the open items only.
 */
final class ManualSyncOutsideItemWindowsTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Provider/Tracker/Fixtures';
    private const string TWO_MARKETS_CONFIG_FILE = 'pixelcast-boursorama-two-markets.yaml';
    private const string BOURSORAMA_BASE_URI = 'https://www.boursorama.com/';
    private const string MARKET_TIMEZONE = 'Europe/Paris';
    private const string INSTANT_AFTER_EVERY_CLOSING = '2026-08-03 23:00:00';
    private const string INSTANT_BEFORE_THE_AMERICAN_OPENING = '2026-08-03 10:00:00';

    public function testACycleDispatchedByHandPushesEveryItemWhateverItsOwnWindow(): void
    {
        $client = new RecordingPixelcastClientStub();
        $boursoramaClient = self::fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json');
        $handler = self::buildHandler($boursoramaClient, self::INSTANT_AFTER_EVERY_CLOSING, $client);

        $outcome = $handler(new SyncTrackerMessage(BoursoramaSyncConfig::syncType())->dispatchedByHand());

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame(2, $boursoramaClient->getRequestsCount());
        self::assertSame(['1RTDCAM', '1RTCW8'], self::pushedTrackerNames($client));
    }

    public function testTheSameCycleScheduledOutsideEveryItemWindowReachesNothing(): void
    {
        $client = new RecordingPixelcastClientStub();
        $boursoramaClient = self::fixtureClient();
        $handler = self::buildHandler($boursoramaClient, self::INSTANT_AFTER_EVERY_CLOSING, $client);

        $outcome = $handler(new SyncTrackerMessage(BoursoramaSyncConfig::syncType()));

        self::assertSame(SyncOutcome::Skipped, $outcome);
        self::assertSame(0, $boursoramaClient->getRequestsCount());
        self::assertSame([], self::pushedTrackerNames($client));
    }

    public function testAScheduledCycleStillPushesTheItemsThatAreOpen(): void
    {
        $client = new RecordingPixelcastClientStub();
        $boursoramaClient = self::fixtureClient('boursorama-ticks-dcam.json');
        $handler = self::buildHandler($boursoramaClient, self::INSTANT_BEFORE_THE_AMERICAN_OPENING, $client);

        $outcome = $handler(new SyncTrackerMessage(BoursoramaSyncConfig::syncType()));

        self::assertSame(SyncOutcome::Pushed, $outcome);
        self::assertSame(1, $boursoramaClient->getRequestsCount());
        self::assertSame(['1RTDCAM'], self::pushedTrackerNames($client));
    }

    /**
     * @return list<string>
     */
    private static function pushedTrackerNames(RecordingPixelcastClientStub $client): array
    {
        return array_map(
            static fn (TrackerPayload $trackerPayload): string => $trackerPayload->name,
            $client->pushedTrackers,
        );
    }

    private static function buildHandler(
        MockHttpClient $boursoramaClient,
        string $instant,
        RecordingPixelcastClientStub $client,
    ): SyncTrackerHandler {
        $clock = new MockClock($instant, self::MARKET_TIMEZONE);
        $trackerProvider = new BoursoramaTrackerProvider(
            $boursoramaClient,
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.self::TWO_MARKETS_CONFIG_FILE),
            new NullLogger(),
        );

        return new SyncTrackerHandler(
            [$trackerProvider],
            $client,
            new NullLogger(),
            new LastSuccessfulSyncStore(new ArrayAdapter(), $clock),
            $clock,
        );
    }

    private static function fixtureClient(string ...$fixtureFileNames): MockHttpClient
    {
        $responses = array_map(
            static fn (string $fixtureFileName): MockResponse => MockResponse::fromFile(self::FIXTURES_DIR.'/'.$fixtureFileName),
            $fixtureFileNames,
        );

        return new MockHttpClient($responses, self::BOURSORAMA_BASE_URI);
    }
}
