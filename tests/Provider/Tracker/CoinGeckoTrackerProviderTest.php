<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\CoinGeckoSyncConfig;
use App\Provider\Tracker\CoinGeckoMidnightPriceProvider;
use App\Provider\Tracker\CoinGeckoTrackerProvider;
use App\Tests\Factory\CoinGeckoMidnightPriceProviderFactory;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tracker\AllTimeHigh;
use App\Tracker\AllTimeHighStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoinGeckoTrackerProviderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string COINGECKO_BASE_URI = 'https://api.coingecko.com/api/v3/';
    private const string SINGLE_CURRENCY_CONFIG_FILE = 'pixelcast.yaml';
    private const string MIXED_CURRENCIES_CONFIG_FILE = 'pixelcast-mixed-currencies.yaml';
    private const string LABELLED_ITEM_CONFIG_FILE = 'pixelcast-coingecko-labelled-item.yaml';
    private const string BOTTOM_LINE_CONFIG_FILE = 'pixelcast-coingecko-bottom-line.yaml';
    private const string SYNC_INSTANT = '2026-08-03 16:00:00';
    private const string BITCOIN_ALL_TIME_HIGH_DATE = '2025-10-06T10:57:42+00:00';

    private string $temporaryDirectory;
    private string $databaseFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-coingecko-tracker-'.bin2hex(random_bytes(6));
        $this->databaseFilePath = $this->temporaryDirectory.'/tracker-all-time-high.sqlite';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testFetchTrackersBuildsPayloadsFromFixture(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI));

        $trackerPayloads = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(2, $trackerPayloads);

        [$bitcoinPayload, $ethereumPayload] = $trackerPayloads;

        self::assertSame('BTC', $bitcoinPayload->name);
        self::assertSame('BTC', $bitcoinPayload->symbol);
        self::assertSame('bitcoin', $bitcoinPayload->iconName);
        self::assertSame('EUR', $bitcoinPayload->currency);
        self::assertSame(45450.53, $bitcoinPayload->currentValue);
        self::assertSame(3.3, $bitcoinPayload->changePercentage);
        self::assertSame('#00FF00', $bitcoinPayload->symbolColor?->hexCode);
        self::assertSame('#00FF00', $bitcoinPayload->sparklineColor?->hexCode);
        self::assertSame('Volume : 18B', $bitcoinPayload->bottomText);
        self::assertSame('7d', $bitcoinPayload->sparklinePeriod);

        self::assertSame('ETH', $ethereumPayload->name);
        self::assertSame('ETH', $ethereumPayload->symbol);
        self::assertSame('ethereum', $ethereumPayload->iconName);
        self::assertSame('EUR', $ethereumPayload->currency);
        self::assertSame(2455.12, $ethereumPayload->currentValue);
        self::assertSame(-3.72, $ethereumPayload->changePercentage);
        self::assertSame('#FF0000', $ethereumPayload->symbolColor?->hexCode);
        self::assertSame('#FF0000', $ethereumPayload->sparklineColor?->hexCode);
        self::assertSame('Volume : 9.8B', $ethereumPayload->bottomText);
    }

    public function testEachPayloadDeclaresTheSilenceDerivedFromTheGroupInterval(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI));

        $trackerPayloads = $provider->fetchTrackers(self::syncInstant());

        self::assertSame(2700, $trackerPayloads[0]->staleAfterInSeconds);
        self::assertNull($trackerPayloads[0]->staleBehavior);
    }

    public function testACoinWithoutAMidnightPriceIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $midnightPriceProvider = CoinGeckoMidnightPriceProviderFactory::withHttpClient(
            new MockHttpClient(new MockResponse('', ['error' => 'connection refused']), self::COINGECKO_BASE_URI),
            $logger,
        );

        $trackerPayloads = $this
            ->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets-bitcoin-only.json'), self::COINGECKO_BASE_URI), $logger, midnightPriceProvider: $midnightPriceProvider)
            ->fetchTrackers(self::syncInstant());

        self::assertSame([], $trackerPayloads);
        self::assertContains('CoinGecko tracker skipped for lack of a midnight price', array_column($logger->records, 'message'));
    }

    public function testAMarketWithoutVolumeCarriesNoBottomText(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets-bitcoin-only.json'), self::COINGECKO_BASE_URI));

        [$bitcoinPayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertNull($bitcoinPayload->bottomText);
    }

    public function testAConfiguredLabelColorAndBottomTextWinOverTheDerivedOnes(): void
    {
        $provider = $this->buildProvider(
            new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI),
            configFileName: self::LABELLED_ITEM_CONFIG_FILE,
        );

        $trackerPayloads = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('Bitcoin', $trackerPayloads[0]->symbol);
        self::assertSame('BTC', $trackerPayloads[0]->name);
        self::assertSame('#4CAF50', $trackerPayloads[0]->symbolColor?->hexCode);
        self::assertSame('#00FF00', $trackerPayloads[0]->sparklineColor?->hexCode);
        self::assertSame('Réserve de valeur', $trackerPayloads[0]->bottomText);
    }

    public function testASeriesShorterThanTheChartIsSentWhole(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI));

        [$bitcoinPayload, $ethereumPayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(30, $bitcoinPayload->sparklinePoints);
        self::assertSame(44000.0, $bitcoinPayload->sparklinePoints[0]);
        self::assertSame(45450.0, $bitcoinPayload->sparklinePoints[29]);

        self::assertCount(30, $ethereumPayload->sparklinePoints);
        self::assertSame(2600.0, $ethereumPayload->sparklinePoints[0]);
        self::assertSame(2455.0, $ethereumPayload->sparklinePoints[29]);
    }

    public function testTheHourlyWeekIsDownsampledToOnePointPerChartColumnKeepingBothEnds(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets-hourly-week.json'), self::COINGECKO_BASE_URI));

        [$bitcoinPayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(63, $bitcoinPayload->sparklinePoints);
        self::assertSame(44000.0, $bitcoinPayload->sparklinePoints[0]);
        self::assertSame(45670.0, $bitcoinPayload->sparklinePoints[62]);
        self::assertSame('7d', $bitcoinPayload->sparklinePeriod);
    }

    public function testRequestTargetsCoinGeckoMarketsEndpointWithExpectedQuery(): void
    {
        $response = self::fixtureResponse('coingecko-markets.json');

        $this->buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI))->fetchTrackers(self::syncInstant());

        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringStartsWith(self::COINGECKO_BASE_URI.'coins/markets?', $response->getRequestUrl());

        $queryParameters = self::queryParameters($response);
        self::assertSame('eur', $queryParameters['vs_currency'] ?? null);
        self::assertSame('bitcoin,ethereum', $queryParameters['ids'] ?? null);
        self::assertSame('true', $queryParameters['sparkline'] ?? null);
    }

    public function testItemsSharingTheSameCurrencyAreFetchedInASingleRequest(): void
    {
        $httpClient = new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI);

        $this->buildProvider($httpClient)->fetchTrackers(self::syncInstant());

        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testDifferentCurrenciesTriggerOneRequestPerCurrency(): void
    {
        $bitcoinResponse = self::fixtureResponse('coingecko-markets-bitcoin-only.json');
        $ethereumResponse = self::fixtureResponse('coingecko-markets-ethereum-only.json');
        $httpClient = new MockHttpClient([$bitcoinResponse, $ethereumResponse], self::COINGECKO_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, configFileName: self::MIXED_CURRENCIES_CONFIG_FILE)->fetchTrackers(self::syncInstant());

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertSame('eur', self::queryParameters($bitcoinResponse)['vs_currency'] ?? null);
        self::assertSame('bitcoin', self::queryParameters($bitcoinResponse)['ids'] ?? null);
        self::assertSame('usd', self::queryParameters($ethereumResponse)['vs_currency'] ?? null);
        self::assertSame('ethereum', self::queryParameters($ethereumResponse)['ids'] ?? null);

        self::assertSame(
            ['EUR', 'USD'],
            array_map(static fn (TrackerPayload $trackerPayload): ?string => $trackerPayload->currency, $trackerPayloads),
        );
    }

    public function testApiKeyIsSentAsHeaderWhenConfigured(): void
    {
        $response = self::fixtureResponse('coingecko-markets.json');

        $this->buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI), apiKey: 'demo-key')->fetchTrackers(self::syncInstant());

        self::assertContains('x-cg-demo-api-key: demo-key', self::requestHeaders($response));
    }

    public function testApiKeyHeaderIsOmittedWhenNotConfigured(): void
    {
        $response = self::fixtureResponse('coingecko-markets.json');

        $this->buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI))->fetchTrackers(self::syncInstant());

        foreach (self::requestHeaders($response) as $header) {
            self::assertStringStartsNotWith('x-cg-demo-api-key:', $header);
        }
    }

    public function testTransportErrorSkipsThatCurrencyGroupAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(
            [new MockResponse('', ['error' => 'connection refused']), self::fixtureResponse('coingecko-markets-ethereum-only.json')],
            self::COINGECKO_BASE_URI,
        );

        $trackerPayloads = $this->buildProvider($httpClient, $logger, self::MIXED_CURRENCIES_CONFIG_FILE)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('ETH', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['CoinGecko request failed'], array_column($logger->records, 'message'));
    }

    public function testMissingCoinInTheResponseIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('coingecko-markets-bitcoin-only.json'), self::COINGECKO_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('BTC', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['CoinGecko response is missing a requested coin'], array_column($logger->records, 'message'));
        self::assertSame('ethereum', $logger->records[0]['context']['coin_id'] ?? null);
    }

    public function testMalformedMarketFieldsAreSkippedAndLogAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('coingecko-markets-malformed.json'), self::COINGECKO_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('ETH', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Unexpected CoinGecko market shape'], array_column($logger->records, 'message'));
        self::assertSame('bitcoin', $logger->records[0]['context']['coin_id'] ?? null);
    }

    public function testWithoutABottomLineTheTradedVolumeStillFillsTheBottomRow(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI));

        [$bitcoinPayload, $ethereumPayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertSame('Volume : 18B', $bitcoinPayload->bottomText);
        self::assertSame('Volume : 9.8B', $ethereumPayload->bottomText);
    }

    public function testTheAllTimeHighServedByTheSourceIsStored(): void
    {
        $allTimeHighStore = $this->buildAllTimeHighStore();

        $this->fetchWithStore($allTimeHighStore, 'coingecko-markets.json');

        self::assertSame(107662.0, self::storedPriceOf($allTimeHighStore, 'bitcoin'));
        self::assertSame(4890.5, self::storedPriceOf($allTimeHighStore, 'ethereum'));
    }

    public function testTheAllTimeHighServedByTheSourceKeepsTheDateItWasReachedOn(): void
    {
        $allTimeHighStore = $this->buildAllTimeHighStore();

        $this->fetchWithStore($allTimeHighStore, 'coingecko-markets.json');

        $storedHigh = self::storedHighOf($allTimeHighStore, 'bitcoin');
        self::assertSame(self::BITCOIN_ALL_TIME_HIGH_DATE, $storedHigh?->reachedAt?->format(\DateTimeInterface::RFC3339));
    }

    public function testAnAllTimeHighServedByTheSourceBelowTheStoredOneDoesNotLowerIt(): void
    {
        $allTimeHighStore = $this->buildAllTimeHighStore();
        $priceSeenOnAnEarlierCycle = 108400.0;
        $allTimeHighStore->raiseTo(self::observedHigh('bitcoin', $priceSeenOnAnEarlierCycle));

        $this->fetchWithStore($allTimeHighStore, 'coingecko-markets.json');

        self::assertSame($priceSeenOnAnEarlierCycle, self::storedPriceOf($allTimeHighStore, 'bitcoin'));
    }

    public function testAMarketWithoutAnAthFieldStoresTheCurrentPriceAsTheHighestKnown(): void
    {
        $allTimeHighStore = $this->buildAllTimeHighStore();

        $this->fetchWithStore($allTimeHighStore, 'coingecko-markets-hourly-week.json');

        $storedHigh = self::storedHighOf($allTimeHighStore, 'bitcoin');
        self::assertSame(45670.0, $storedHigh?->price);
        self::assertSame(self::SYNC_INSTANT, $storedHigh->reachedAt?->format('Y-m-d H:i:s'));
    }

    public function testAMalformedAllTimeHighDateStoresTheHighWithoutADateReached(): void
    {
        $allTimeHighStore = $this->buildAllTimeHighStore();

        $this->fetchWithStore($allTimeHighStore, 'coingecko-markets-malformed.json');

        $storedHigh = self::storedHighOf($allTimeHighStore, 'ethereum');
        self::assertSame(4890.5, $storedHigh?->price);
        self::assertNull($storedHigh->reachedAt);
    }

    public function testAPriceAboveTheStoredAllTimeHighRaisesIt(): void
    {
        $allTimeHighStore = $this->buildAllTimeHighStore();
        $allTimeHighStore->raiseTo(self::observedHigh('bitcoin', 40000.0));

        $this->fetchWithStore($allTimeHighStore, 'coingecko-markets-hourly-week.json');

        self::assertSame(45670.0, self::storedPriceOf($allTimeHighStore, 'bitcoin'));
    }

    public function testBottomLineAthFillsTheBottomRowWithTheStoredAllTimeHigh(): void
    {
        $trackerPayloads = $this->buildBottomLineProvider()->fetchTrackers(self::syncInstant());

        self::assertSame('ATH 107662', $trackerPayloads[0]->bottomText);
        self::assertSame('ATH 107662$', $trackerPayloads[2]->bottomText);
    }

    public function testBottomLineVolumeFillsTheBottomRowWithTheTradedVolume(): void
    {
        $trackerPayloads = $this->buildBottomLineProvider()->fetchTrackers(self::syncInstant());

        self::assertSame('Volume : 9.8B', $trackerPayloads[1]->bottomText);
    }

    public function testAFixedBottomTextStillWinsOverBottomLineAth(): void
    {
        $trackerPayloads = $this->buildBottomLineProvider()->fetchTrackers(self::syncInstant());

        self::assertSame('Réserve de valeur', $trackerPayloads[3]->bottomText);
    }

    public function testAnUnreachableStoreLeavesTheBottomRowEmptyWithoutLosingTheTracker(): void
    {
        $logger = new RecordingLoggerStub();

        $trackerPayloads = $this->buildBottomLineProvider($this->buildUnreachableAllTimeHighStore($logger))->fetchTrackers(self::syncInstant());

        self::assertCount(4, $trackerPayloads);
        self::assertNull($trackerPayloads[0]->bottomText);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
    }

    private function fetchWithStore(AllTimeHighStore $allTimeHighStore, string $marketsFixtureFileName): void
    {
        $this
            ->buildProvider(
                new MockHttpClient(self::fixtureResponse($marketsFixtureFileName), self::COINGECKO_BASE_URI),
                allTimeHighStore: $allTimeHighStore,
            )
            ->fetchTrackers(self::syncInstant());
    }

    private static function storedHighOf(AllTimeHighStore $allTimeHighStore, string $coinId): ?AllTimeHigh
    {
        return $allTimeHighStore->readAllTimeHigh(CoinGeckoSyncConfig::syncType(), $coinId, 'eur');
    }

    private static function storedPriceOf(AllTimeHighStore $allTimeHighStore, string $coinId): ?float
    {
        return self::storedHighOf($allTimeHighStore, $coinId)?->price;
    }

    private static function observedHigh(string $coinId, float $price): AllTimeHigh
    {
        $earlierCycle = new \DateTimeImmutable('2026-08-03 10:00:00');

        return new AllTimeHigh(CoinGeckoSyncConfig::syncType(), $coinId, 'eur', $price, $earlierCycle, $earlierCycle);
    }

    private function buildProvider(
        MockHttpClient $httpClient,
        ?LoggerInterface $logger = null,
        string $configFileName = self::SINGLE_CURRENCY_CONFIG_FILE,
        ?string $apiKey = null,
        ?CoinGeckoMidnightPriceProvider $midnightPriceProvider = null,
        ?AllTimeHighStore $allTimeHighStore = null,
    ): CoinGeckoTrackerProvider {
        return new CoinGeckoTrackerProvider(
            $httpClient,
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
            $midnightPriceProvider ?? CoinGeckoMidnightPriceProviderFactory::withFixturePrices(),
            $allTimeHighStore ?? $this->buildAllTimeHighStore(),
            new MockClock(self::SYNC_INSTANT),
            $logger ?? new NullLogger(),
            $apiKey,
        );
    }

    private function buildAllTimeHighStore(?LoggerInterface $logger = null): AllTimeHighStore
    {
        return new AllTimeHighStore($this->databaseFilePath, $logger ?? new NullLogger());
    }

    /**
     * A regular file cannot hold a directory, so neither the parent nor the database can be created.
     */
    private function buildUnreachableAllTimeHighStore(LoggerInterface $logger): AllTimeHighStore
    {
        $blockingFile = $this->temporaryDirectory.'/not-a-directory';
        new Filesystem()->dumpFile($blockingFile, '');

        return new AllTimeHighStore($blockingFile.'/tracker-all-time-high.sqlite', $logger);
    }

    private function buildBottomLineProvider(?AllTimeHighStore $allTimeHighStore = null): CoinGeckoTrackerProvider
    {
        $httpClient = new MockHttpClient(
            [self::fixtureResponse('coingecko-markets.json'), self::fixtureResponse('coingecko-markets.json')],
            self::COINGECKO_BASE_URI,
        );

        return $this->buildProvider($httpClient, configFileName: self::BOTTOM_LINE_CONFIG_FILE, allTimeHighStore: $allTimeHighStore);
    }

    private static function syncInstant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::SYNC_INSTANT);
    }

    private static function fixtureResponse(string $fixtureFileName): MockResponse
    {
        $rawJson = file_get_contents(self::FIXTURES_DIR.'/'.$fixtureFileName);
        if (false === $rawJson) {
            self::fail(\sprintf('The "%s" fixture could not be read.', $fixtureFileName));
        }

        return new MockResponse($rawJson);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function queryParameters(MockResponse $response): array
    {
        $queryString = parse_url($response->getRequestUrl(), \PHP_URL_QUERY);
        if (!\is_string($queryString)) {
            self::fail('The CoinGecko request carries no query string.');
        }

        parse_str($queryString, $queryParameters);

        return $queryParameters;
    }

    /**
     * @return list<string>
     */
    private static function requestHeaders(MockResponse $response): array
    {
        $headers = $response->getRequestOptions()['headers'] ?? [];
        if (!\is_array($headers)) {
            self::fail('The CoinGecko request carries no readable headers.');
        }

        $stringHeaders = [];
        foreach ($headers as $header) {
            if (\is_string($header)) {
                $stringHeaders[] = $header;
            }
        }

        return $stringHeaders;
    }
}
