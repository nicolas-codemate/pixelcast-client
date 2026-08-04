<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Config\Sync\CoinGeckoSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\MessageHandler\SyncTrackerHandler;
use App\Provider\Tracker\CoinGeckoTrackerProvider;
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

    public function testTrackersReachTheSimulatorInASingleUpstreamCall(): void
    {
        $coinGeckoClient = new MockHttpClient(self::marketsResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI);
        $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock());
        $syncTrackerHandler = $this->buildSyncTrackerHandler($coinGeckoClient, $lastSuccessfulSyncStore);
        $expectedBodies = self::expectedWireBodies('coingecko-markets.json');

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::syncTrackerMessage()), $this->server->serverOutput());
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
            [self::marketsResponse('coingecko-markets.json'), self::marketsResponse('coingecko-markets-next-cycle.json')],
            self::COINGECKO_BASE_URI,
        );
        $syncTrackerHandler = $this->buildSyncTrackerHandler($coinGeckoClient);
        $expectedRefreshedBodies = self::expectedWireBodies('coingecko-markets-next-cycle.json');

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::syncTrackerMessage()), $this->server->serverOutput());
        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::syncTrackerMessage()), $this->server->serverOutput());

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

    private function buildSyncTrackerHandler(
        MockHttpClient $coinGeckoClient,
        LastSuccessfulSyncStore $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock()),
    ): SyncTrackerHandler {
        return new SyncTrackerHandler(
            [self::buildTrackerProvider($coinGeckoClient)],
            $this->buildPixelcastClient(),
            new NullLogger(),
            $lastSuccessfulSyncStore,
        );
    }

    /**
     * Replays the fixture through its own provider to get the very bodies the handler pushes,
     * as they land on the wire: json_encode drops the zero fraction of whole sparkline points.
     *
     * @return array<string, array<string, mixed>> keyed by tracker name
     */
    private static function expectedWireBodies(string $fixtureFileName): array
    {
        $coinGeckoClient = new MockHttpClient(self::marketsResponse($fixtureFileName), self::COINGECKO_BASE_URI);

        $wireBodies = [];
        foreach (self::buildTrackerProvider($coinGeckoClient)->fetchTrackers() as $trackerPayload) {
            $encodedBody = json_encode($trackerPayload->toArray(), \JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $decodedBody */
            $decodedBody = json_decode($encodedBody, true, flags: \JSON_THROW_ON_ERROR);
            $wireBodies[$trackerPayload->name] = $decodedBody;
        }

        return $wireBodies;
    }

    private static function buildTrackerProvider(MockHttpClient $coinGeckoClient): CoinGeckoTrackerProvider
    {
        return new CoinGeckoTrackerProvider(
            $coinGeckoClient,
            SyncsConfigLoaderFactory::forConfigFile(self::trackerFixturesDirectory().'/pixelcast.yaml'),
            new NullLogger(),
        );
    }

    private static function syncTrackerMessage(): SyncTrackerMessage
    {
        return new SyncTrackerMessage(CoinGeckoSyncConfig::syncType());
    }

    private static function marketsResponse(string $fixtureFileName): MockResponse
    {
        return MockResponse::fromFile(self::trackerFixturesDirectory().'/'.$fixtureFileName);
    }

    private static function trackerFixturesDirectory(): string
    {
        return \dirname(__DIR__, 2).'/Provider/Tracker/Fixtures';
    }
}
