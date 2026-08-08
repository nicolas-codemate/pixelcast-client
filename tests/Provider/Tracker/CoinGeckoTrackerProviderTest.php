<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
use App\Provider\Tracker\CoinGeckoMidnightPriceProvider;
use App\Provider\Tracker\CoinGeckoTrackerProvider;
use App\Tests\Factory\CoinGeckoMidnightPriceProviderFactory;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoinGeckoTrackerProviderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string COINGECKO_BASE_URI = 'https://api.coingecko.com/api/v3/';
    private const string SINGLE_CURRENCY_CONFIG_FILE = 'pixelcast.yaml';
    private const string MIXED_CURRENCIES_CONFIG_FILE = 'pixelcast-mixed-currencies.yaml';
    private const string LABELLED_ITEM_CONFIG_FILE = 'pixelcast-coingecko-labelled-item.yaml';

    public function testFetchTrackersBuildsPayloadsFromFixture(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI));

        $trackerPayloads = $provider->fetchTrackers();

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

        $trackerPayloads = $provider->fetchTrackers();

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
            ->fetchTrackers();

        self::assertSame([], $trackerPayloads);
        self::assertContains('CoinGecko tracker skipped for lack of a midnight price', array_column($logger->records, 'message'));
    }

    public function testAMarketWithoutVolumeCarriesNoBottomText(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets-bitcoin-only.json'), self::COINGECKO_BASE_URI));

        [$bitcoinPayload] = $provider->fetchTrackers();

        self::assertNull($bitcoinPayload->bottomText);
    }

    public function testAConfiguredLabelColorAndBottomTextWinOverTheDerivedOnes(): void
    {
        $provider = $this->buildProvider(
            new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI),
            configFileName: self::LABELLED_ITEM_CONFIG_FILE,
        );

        $trackerPayloads = $provider->fetchTrackers();

        self::assertCount(1, $trackerPayloads);
        self::assertSame('Bitcoin', $trackerPayloads[0]->symbol);
        self::assertSame('BTC', $trackerPayloads[0]->name);
        self::assertSame('#4CAF50', $trackerPayloads[0]->symbolColor?->hexCode);
        self::assertSame('#00FF00', $trackerPayloads[0]->sparklineColor?->hexCode);
        self::assertSame('Réserve de valeur', $trackerPayloads[0]->bottomText);
    }

    public function testSparklineIsDownsampledToAtMost24PointsKeepingFirstAndLastPoint(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('coingecko-markets.json'), self::COINGECKO_BASE_URI));

        [$bitcoinPayload, $ethereumPayload] = $provider->fetchTrackers();

        self::assertCount(24, $bitcoinPayload->sparklinePoints);
        self::assertSame(44000.0, $bitcoinPayload->sparklinePoints[0]);
        self::assertSame(45450.0, $bitcoinPayload->sparklinePoints[23]);

        self::assertCount(24, $ethereumPayload->sparklinePoints);
        self::assertSame(2600.0, $ethereumPayload->sparklinePoints[0]);
        self::assertSame(2455.0, $ethereumPayload->sparklinePoints[23]);
    }

    public function testRequestTargetsCoinGeckoMarketsEndpointWithExpectedQuery(): void
    {
        $response = self::fixtureResponse('coingecko-markets.json');

        $this->buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI))->fetchTrackers();

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

        $this->buildProvider($httpClient)->fetchTrackers();

        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testDifferentCurrenciesTriggerOneRequestPerCurrency(): void
    {
        $bitcoinResponse = self::fixtureResponse('coingecko-markets-bitcoin-only.json');
        $ethereumResponse = self::fixtureResponse('coingecko-markets-ethereum-only.json');
        $httpClient = new MockHttpClient([$bitcoinResponse, $ethereumResponse], self::COINGECKO_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, configFileName: self::MIXED_CURRENCIES_CONFIG_FILE)->fetchTrackers();

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

        $this->buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI), apiKey: 'demo-key')->fetchTrackers();

        self::assertContains('x-cg-demo-api-key: demo-key', self::requestHeaders($response));
    }

    public function testApiKeyHeaderIsOmittedWhenNotConfigured(): void
    {
        $response = self::fixtureResponse('coingecko-markets.json');

        $this->buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI))->fetchTrackers();

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

        $trackerPayloads = $this->buildProvider($httpClient, $logger, self::MIXED_CURRENCIES_CONFIG_FILE)->fetchTrackers();

        self::assertCount(1, $trackerPayloads);
        self::assertSame('ETH', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['CoinGecko request failed'], array_column($logger->records, 'message'));
    }

    public function testMissingCoinInTheResponseIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('coingecko-markets-bitcoin-only.json'), self::COINGECKO_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers();

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

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers();

        self::assertCount(1, $trackerPayloads);
        self::assertSame('ETH', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Unexpected CoinGecko market shape'], array_column($logger->records, 'message'));
        self::assertSame('bitcoin', $logger->records[0]['context']['coin_id'] ?? null);
    }

    private function buildProvider(
        MockHttpClient $httpClient,
        ?LoggerInterface $logger = null,
        string $configFileName = self::SINGLE_CURRENCY_CONFIG_FILE,
        ?string $apiKey = null,
        ?CoinGeckoMidnightPriceProvider $midnightPriceProvider = null,
    ): CoinGeckoTrackerProvider {
        return new CoinGeckoTrackerProvider(
            $httpClient,
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
            $midnightPriceProvider ?? CoinGeckoMidnightPriceProviderFactory::withFixturePrices(),
            new MockClock(),
            $logger ?? new NullLogger(),
            $apiKey,
        );
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
