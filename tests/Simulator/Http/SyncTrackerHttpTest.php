<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\MessageHandler\SyncTrackerHandler;
use App\Provider\Tracker\CoinGeckoTrackerProvider;
use App\Provider\Tracker\TrackerProviderInterface;
use App\Provider\Tracker\TwelveDataTrackerProvider;
use App\Simulator\State\PersistedStateReader;
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
    private const string TWELVEDATA_FIXTURE_FILE = 'twelvedata-time-series-three-categories.json';

    public function testTrackersReachTheSimulatorInASingleUpstreamCall(): void
    {
        $coinGeckoClient = new MockHttpClient(self::trackerFixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI);
        $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock());
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildCoinGeckoProvider($coinGeckoClient), $lastSuccessfulSyncStore);
        $expectedBodies = self::expectedWireBodies(self::buildCoinGeckoProvider(
            new MockHttpClient(self::trackerFixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI),
        ));

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
        $coinGeckoClient = new MockHttpClient(
            [self::trackerFixtureResponse('coingecko-markets.json'), self::trackerFixtureResponse('coingecko-markets-next-cycle.json')],
            self::COINGECKO_BASE_URI,
        );
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildCoinGeckoProvider($coinGeckoClient));
        $expectedRefreshedBodies = self::expectedWireBodies(self::buildCoinGeckoProvider(
            new MockHttpClient(self::trackerFixtureResponse('coingecko-markets-next-cycle.json'), self::COINGECKO_BASE_URI),
        ));

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
        $twelveDataClient = new MockHttpClient(self::trackerFixtureResponse(self::TWELVEDATA_FIXTURE_FILE), self::TWELVEDATA_BASE_URI);
        $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock());
        $syncTrackerHandler = $this->buildSyncTrackerHandler(self::buildTwelveDataProvider($twelveDataClient), $lastSuccessfulSyncStore);
        $expectedBodies = self::expectedWireBodies(self::buildTwelveDataProvider(
            new MockHttpClient(self::trackerFixtureResponse(self::TWELVEDATA_FIXTURE_FILE), self::TWELVEDATA_BASE_URI),
        ));

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

    public function testAFailingUpstreamCallLeavesTheSimulatorUntouched(): void
    {
        $twelveDataClient = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']), self::TWELVEDATA_BASE_URI);
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
        );
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
        foreach ($trackerProvider->fetchTrackers() as $trackerPayload) {
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

    private static function coinGeckoMessage(): SyncTrackerMessage
    {
        return new SyncTrackerMessage(CoinGeckoSyncConfig::syncType());
    }

    private static function twelveDataMessage(): SyncTrackerMessage
    {
        return new SyncTrackerMessage(TwelveDataSyncConfig::syncType());
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
