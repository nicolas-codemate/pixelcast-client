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

    // The provider keeps no cache, so calling fetchTrackers() here to derive the expected
    // bodies would consume the mocked response and leave the handler with nothing to push.
    // Sparkline points are integers because json_encode drops the zero fraction on the wire.
    private const array EXPECTED_BITCOIN_BODY = [
        'symbol' => 'BTC',
        'icon' => 'bitcoin',
        'currency' => 'EUR',
        'value' => 45450.53,
        'change' => 2.47,
        'sparkline' => [44000, 44050, 44150, 44200, 44250, 44300, 44400, 44450, 44500, 44550, 44650, 44700, 44750, 44800, 44900, 44950, 45000, 45050, 45150, 45200, 45250, 45300, 45400, 45450],
        'symbolColor' => '#00FF00',
        'sparklineColor' => '#00FF00',
    ];

    private const array EXPECTED_ETHEREUM_BODY = [
        'symbol' => 'ETH',
        'icon' => 'ethereum',
        'currency' => 'EUR',
        'value' => 2455.12,
        'change' => -3.85,
        'sparkline' => [2600, 2595, 2585, 2580, 2575, 2570, 2560, 2555, 2550, 2545, 2535, 2530, 2525, 2520, 2510, 2505, 2500, 2495, 2485, 2480, 2475, 2470, 2460, 2455],
        'symbolColor' => '#FF0000',
        'sparklineColor' => '#FF0000',
    ];

    private const array EXPECTED_REFRESHED_BITCOIN_BODY = [
        'symbol' => 'BTC',
        'icon' => 'bitcoin',
        'currency' => 'EUR',
        'value' => 43120.10,
        'change' => -1.85,
        'sparkline' => [46000, 45900, 45700, 45600, 45500, 45400, 45200, 45100, 45000, 44900, 44700, 44600, 44500, 44400, 44200, 44100, 44000, 43900, 43700, 43600, 43500, 43400, 43200, 43100],
        'symbolColor' => '#FF0000',
        'sparklineColor' => '#FF0000',
    ];

    private const array EXPECTED_REFRESHED_ETHEREUM_BODY = [
        'symbol' => 'ETH',
        'icon' => 'ethereum',
        'currency' => 'EUR',
        'value' => 2610.44,
        'change' => 4.12,
        'sparkline' => [2450, 2455, 2465, 2470, 2475, 2480, 2490, 2495, 2500, 2505, 2515, 2520, 2525, 2530, 2540, 2545, 2550, 2555, 2565, 2570, 2575, 2580, 2590, 2595],
        'symbolColor' => '#00FF00',
        'sparklineColor' => '#00FF00',
    ];

    public function testTrackersReachTheSimulatorInASingleUpstreamCall(): void
    {
        $coinGeckoClient = new MockHttpClient(self::marketsResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI);
        $lastSuccessfulSyncStore = new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock());
        $syncTrackerHandler = $this->buildSyncTrackerHandler($coinGeckoClient, $lastSuccessfulSyncStore);

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::syncTrackerMessage()), $this->server->serverOutput());
        self::assertSame(1, $coinGeckoClient->getRequestsCount());

        $inspectPayload = $this->inspect();
        $this->assertLoggedRequest($inspectPayload, 0, 2, 'POST', '/api/tracker', self::EXPECTED_BITCOIN_BODY);
        $this->assertLoggedRequest($inspectPayload, 1, 2, 'POST', '/api/tracker', self::EXPECTED_ETHEREUM_BODY);

        $trackerState = self::domainState($inspectPayload, 'trackers');
        self::assertSame(2, $trackerState['count'] ?? null, $this->server->serverOutput());
        self::assertSame(
            ['BTC' => self::EXPECTED_BITCOIN_BODY, 'ETH' => self::EXPECTED_ETHEREUM_BODY],
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
        $syncTrackerHandler = $this->buildSyncTrackerHandler($coinGeckoClient, new LastSuccessfulSyncStore(new ArrayAdapter(), new MockClock()));

        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::syncTrackerMessage()), $this->server->serverOutput());
        self::assertSame(SyncOutcome::Pushed, $syncTrackerHandler(self::syncTrackerMessage()), $this->server->serverOutput());

        $inspectPayload = $this->inspect();
        self::assertCount(4, self::loggedRequests($inspectPayload), $this->server->serverOutput());

        $trackerState = self::domainState($inspectPayload, 'trackers');
        self::assertSame(2, $trackerState['count'] ?? null, $this->server->serverOutput());
        self::assertSame(
            ['BTC' => self::EXPECTED_REFRESHED_BITCOIN_BODY, 'ETH' => self::EXPECTED_REFRESHED_ETHEREUM_BODY],
            PersistedStateReader::payloadMap($trackerState['trackers'] ?? null),
            $this->server->serverOutput(),
        );
    }

    private function buildSyncTrackerHandler(MockHttpClient $coinGeckoClient, LastSuccessfulSyncStore $lastSuccessfulSyncStore): SyncTrackerHandler
    {
        $trackerProvider = new CoinGeckoTrackerProvider(
            $coinGeckoClient,
            SyncsConfigLoaderFactory::forConfigFile(self::trackerFixturesDirectory().'/pixelcast.yaml'),
            new NullLogger(),
        );

        return new SyncTrackerHandler(
            [$trackerProvider],
            $this->buildPixelcastClient(),
            new NullLogger(),
            $lastSuccessfulSyncStore,
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
