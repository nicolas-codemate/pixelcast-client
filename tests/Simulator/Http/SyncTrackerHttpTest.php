<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\MessageHandler\SyncTrackerHandler;
use App\Provider\Tracker\BoursoramaTrackerProvider;
use App\Provider\Tracker\CoinGeckoTrackerProvider;
use App\Provider\Tracker\TrackerProviderInterface;
use App\Provider\Tracker\TwelveDataTrackerProvider;
use App\Simulator\State\PersistedStateReader;
use App\Tests\Factory\CoinGeckoMidnightPriceProviderFactory;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyncTrackerHttpTest extends SimulatorHttpTestCase
{
    private const string COINGECKO_BASE_URI = 'https://api.coingecko.com/api/v3/';
    private const string TWELVEDATA_BASE_URI = 'https://api.twelvedata.com/';
    private const string BOURSORAMA_BASE_URI = 'https://www.boursorama.com/';
    private const string COINGECKO_FIXTURE_FILE = 'coingecko-markets.json';
    private const string COINGECKO_NEXT_CYCLE_FIXTURE_FILE = 'coingecko-markets-next-cycle.json';
    private const string TWELVEDATA_FIXTURE_FILE = 'twelvedata-time-series-three-categories.json';
    private const string BOURSORAMA_DCAM_FIXTURE_FILE = 'boursorama-ticks-dcam.json';
    private const string BOURSORAMA_CW8_FIXTURE_FILE = 'boursorama-ticks-cw8.json';

    public function testTrackersReachTheSimulatorInASingleUpstreamCall(): void
    {
        $coinGeckoClient = self::coinGeckoClient(self::COINGECKO_FIXTURE_FILE);
        $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock());
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildCoinGeckoProvider($coinGeckoClient), $lastSuccessfulSyncStore);
        $expectedBodies = self::coinGeckoWireBodies(self::COINGECKO_FIXTURE_FILE);

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::coinGeckoMessage()), $this->server->serverOutput());
        self::assertSame(1, $coinGeckoClient->getRequestsCount());

        $inspectPayload = $this->inspect();
        $this->assertLoggedRequest($inspectPayload, 0, 2, 'POST', '/api/tracker', $expectedBodies['BTC']);
        $this->assertLoggedRequest($inspectPayload, 1, 2, 'POST', '/api/tracker', $expectedBodies['ETH']);

        $trackerState = self::domainState($inspectPayload, 'trackers');
        self::assertSame(2, $trackerState['count'] ?? null, $this->server->serverOutput());
        self::assertSame(
            $expectedBodies,
            PersistedStateReader::payloadMap($trackerState['trackers'] ?? null),
            $this->server->serverOutput(),
        );

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(CoinGeckoSyncConfig::syncType()));
    }

    public function testASecondCycleRefreshesTheStoredTrackersInPlace(): void
    {
        $coinGeckoClient = self::coinGeckoClient(self::COINGECKO_FIXTURE_FILE, self::COINGECKO_NEXT_CYCLE_FIXTURE_FILE);
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildCoinGeckoProvider($coinGeckoClient));
        $expectedRefreshedBodies = self::coinGeckoWireBodies(self::COINGECKO_NEXT_CYCLE_FIXTURE_FILE);

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::coinGeckoMessage()), $this->server->serverOutput());
        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::coinGeckoMessage()), $this->server->serverOutput());

        $inspectPayload = $this->inspect();
        self::assertCount(4, self::loggedRequests($inspectPayload), $this->server->serverOutput());

        $trackerState = self::domainState($inspectPayload, 'trackers');
        self::assertSame(2, $trackerState['count'] ?? null, $this->server->serverOutput());
        self::assertSame(
            $expectedRefreshedBodies,
            PersistedStateReader::payloadMap($trackerState['trackers'] ?? null),
            $this->server->serverOutput(),
        );
        self::assertSame(43120.10, $expectedRefreshedBodies['BTC']['value']);
        self::assertSame('#FF0000', $expectedRefreshedBodies['BTC']['symbolColor']);
    }

    public function testAStockAnEtfAndAnIndexReachTheSimulatorInASingleUpstreamCall(): void
    {
        $twelveDataClient = self::twelveDataClient(self::trackerFixtureResponse(self::TWELVEDATA_FIXTURE_FILE));
        $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock());
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildTwelveDataProvider($twelveDataClient), $lastSuccessfulSyncStore);
        $expectedBodies = self::twelveDataWireBodies();

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::twelveDataMessage()), $this->server->serverOutput());
        self::assertSame(1, $twelveDataClient->getRequestsCount());

        $inspectPayload = $this->inspect();
        $this->assertLoggedRequest($inspectPayload, 0, 3, 'POST', '/api/tracker', $expectedBodies['AAPL']);
        $this->assertLoggedRequest($inspectPayload, 1, 3, 'POST', '/api/tracker', $expectedBodies['IWDA.AS']);
        $this->assertLoggedRequest($inspectPayload, 2, 3, 'POST', '/api/tracker', $expectedBodies['IXIC']);

        $trackerState = self::domainState($inspectPayload, 'trackers');
        self::assertSame(3, $trackerState['count'] ?? null, $this->server->serverOutput());
        self::assertSame(
            $expectedBodies,
            PersistedStateReader::payloadMap($trackerState['trackers'] ?? null),
            $this->server->serverOutput(),
        );

        self::assertSame('#00FF00', $expectedBodies['AAPL']['symbolColor']);
        self::assertSame('#FF0000', $expectedBodies['IXIC']['symbolColor']);
        // The index block carries no currency, so the one from the configuration file is used.
        self::assertSame('USD', $expectedBodies['IXIC']['currency']);

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(TwelveDataSyncConfig::syncType()));
    }

    public function testTwoEuropeanEtfsReachTheSimulatorInOneUpstreamCallEach(): void
    {
        $boursoramaClient = self::boursoramaClient(self::BOURSORAMA_DCAM_FIXTURE_FILE, self::BOURSORAMA_CW8_FIXTURE_FILE);
        $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock());
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildBoursoramaProvider($boursoramaClient), $lastSuccessfulSyncStore);
        $expectedBodies = self::boursoramaWireBodies();

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::boursoramaMessage()), $this->server->serverOutput());
        // Boursorama serves one symbol per call, unlike the single grouped call of twelvedata.
        self::assertSame(2, $boursoramaClient->getRequestsCount());

        $inspectPayload = $this->inspect();
        $this->assertLoggedRequest($inspectPayload, 0, 2, 'POST', '/api/tracker', $expectedBodies['1RTDCAM']);
        $this->assertLoggedRequest($inspectPayload, 1, 2, 'POST', '/api/tracker', $expectedBodies['1RTCW8']);

        $trackerState = self::domainState($inspectPayload, 'trackers');
        self::assertSame(2, $trackerState['count'] ?? null, $this->server->serverOutput());
        self::assertSame(
            $expectedBodies,
            PersistedStateReader::payloadMap($trackerState['trackers'] ?? null),
            $this->server->serverOutput(),
        );

        self::assertSame('DCAM', $expectedBodies['1RTDCAM']['symbol']);
        self::assertSame('EUR', $expectedBodies['1RTDCAM']['currency']);
        self::assertSame('33d', $expectedBodies['1RTDCAM']['sparklinePeriod']);

        self::assertSame(0, $lastSuccessfulSyncStore->ageInSecondsOf(BoursoramaSyncConfig::syncType()));
    }

    public function testAFailingUpstreamCallLeavesTheSimulatorUntouched(): void
    {
        $twelveDataClient = self::twelveDataClient(new MockResponse('', ['error' => 'connection refused']));
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildTwelveDataProvider($twelveDataClient));

        self::assertSame(SyncOutcome::Skipped, $syncTrackerHandler(self::twelveDataMessage()), $this->server->serverOutput());

        $inspectPayload = $this->inspect();
        self::assertCount(0, self::loggedRequests($inspectPayload), $this->server->serverOutput());

        $trackerState = self::domainState($inspectPayload, 'trackers');
        self::assertSame(0, $trackerState['count'] ?? null, $this->server->serverOutput());
        self::assertSame([], PersistedStateReader::payloadMap($trackerState['trackers'] ?? null));
    }

    private function buildSyncTrackerHandler(
        TrackerProviderInterface $trackerProvider,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock()),
    ): SyncTrackerHandler {
        return new SyncTrackerHandler(
            [$trackerProvider],
            $this->buildPixelcastClient(),
            new NullLogger(),
            $lastSuccessfulSyncStore,
            new MockClock(),
        );
    }

    /**
     * @return array<string, array<string, mixed>> keyed by tracker name
     */
    private static function coinGeckoWireBodies(string $fixtureFileName): array
    {
        return self::expectedWireBodies(self::buildCoinGeckoProvider(self::coinGeckoClient($fixtureFileName)));
    }

    /**
     * @return array<string, array<string, mixed>> keyed by tracker name
     */
    private static function twelveDataWireBodies(): array
    {
        return self::expectedWireBodies(self::buildTwelveDataProvider(
            self::twelveDataClient(self::trackerFixtureResponse(self::TWELVEDATA_FIXTURE_FILE)),
        ));
    }

    /**
     * @return array<string, array<string, mixed>> keyed by tracker name
     */
    private static function boursoramaWireBodies(): array
    {
        return self::expectedWireBodies(self::buildBoursoramaProvider(
            self::boursoramaClient(self::BOURSORAMA_DCAM_FIXTURE_FILE, self::BOURSORAMA_CW8_FIXTURE_FILE),
        ));
    }

    /**
     * Replays the provider to get the very bodies the handler pushes, as they land on the
     * wire: json_encode drops the zero fraction of whole sparkline points.
     *
     * @return array<string, array<string, mixed>> keyed by tracker name
     */
    private static function expectedWireBodies(TrackerProviderInterface $trackerProvider): array
    {
        $wireBodies = [];
        foreach ($trackerProvider->fetchTrackers(null) as $trackerPayload) {
            $encodedBody = json_encode($trackerPayload->toArray(), \JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $decodedBody */
            $decodedBody = json_decode($encodedBody, true, flags: \JSON_THROW_ON_ERROR);
            $wireBodies[$trackerPayload->name] = $decodedBody;
        }

        return $wireBodies;
    }

    private static function buildCoinGeckoProvider(MockHttpClient $coinGeckoClient): CoinGeckoTrackerProvider
    {
        return new CoinGeckoTrackerProvider(
            $coinGeckoClient,
            SyncsConfigLoaderFactory::forConfigFile(self::trackerFixturesDirectory().'/pixelcast.yaml'),
            CoinGeckoMidnightPriceProviderFactory::withFixturePrices(),
            new NullLogger(),
        );
    }

    private static function buildTwelveDataProvider(MockHttpClient $twelveDataClient): TwelveDataTrackerProvider
    {
        return new TwelveDataTrackerProvider(
            $twelveDataClient,
            SyncsConfigLoaderFactory::forConfigFile(self::trackerFixturesDirectory().'/pixelcast-twelvedata-three-categories.yaml'),
            new NullLogger(),
            'demo-key',
        );
    }

    private static function buildBoursoramaProvider(MockHttpClient $boursoramaClient): BoursoramaTrackerProvider
    {
        return new BoursoramaTrackerProvider(
            $boursoramaClient,
            SyncsConfigLoaderFactory::forConfigFile(self::trackerFixturesDirectory().'/pixelcast-boursorama.yaml'),
            new NullLogger(),
        );
    }

    private static function coinGeckoClient(string ...$fixtureFileNames): MockHttpClient
    {
        $responses = array_map(self::trackerFixtureResponse(...), $fixtureFileNames);

        return new MockHttpClient($responses, self::COINGECKO_BASE_URI);
    }

    private static function twelveDataClient(MockResponse $response): MockHttpClient
    {
        return new MockHttpClient($response, self::TWELVEDATA_BASE_URI);
    }

    private static function boursoramaClient(string ...$fixtureFileNames): MockHttpClient
    {
        $responses = array_map(self::trackerFixtureResponse(...), $fixtureFileNames);

        return new MockHttpClient($responses, self::BOURSORAMA_BASE_URI);
    }

    private static function coinGeckoMessage(): SyncTrackerMessage
    {
        return new SyncTrackerMessage(CoinGeckoSyncConfig::syncType());
    }

    private static function twelveDataMessage(): SyncTrackerMessage
    {
        return new SyncTrackerMessage(TwelveDataSyncConfig::syncType());
    }

    private static function boursoramaMessage(): SyncTrackerMessage
    {
        return new SyncTrackerMessage(BoursoramaSyncConfig::syncType());
    }

    private static function trackerFixtureResponse(string $fixtureFileName): MockResponse
    {
        return MockResponse::fromFile(self::trackerFixturesDirectory().'/'.$fixtureFileName);
    }

    private static function trackerFixturesDirectory(): string
    {
        return \dirname(__DIR__, 2).'/Provider/Tracker/Fixtures';
    }
}
