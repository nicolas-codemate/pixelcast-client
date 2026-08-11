<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
use App\Provider\Tracker\TwelveDataTrackerProvider;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwelveDataTrackerProviderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string TWELVEDATA_BASE_URI = 'https://api.twelvedata.com/';
    private const string TWO_ITEMS_CONFIG_FILE = 'pixelcast-twelvedata.yaml';
    private const string SINGLE_ITEM_CONFIG_FILE = 'pixelcast-twelvedata-single-item.yaml';
    private const string LABELLED_ITEM_CONFIG_FILE = 'pixelcast-twelvedata-labelled-item.yaml';
    private const string THREE_CATEGORIES_CONFIG_FILE = 'pixelcast-twelvedata-three-categories.yaml';
    private const int MAXIMUM_CURRENCY_LENGTH = 7;
    private const string SYNC_INSTANT = '2026-08-03 16:00:00';

    public function testFetchTrackersBuildsPayloadsFromFixture(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('twelvedata-time-series.json'), self::TWELVEDATA_BASE_URI));

        $trackerPayloads = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(2, $trackerPayloads);

        [$applePayload, $worldEtfPayload] = $trackerPayloads;

        self::assertSame('AAPL', $applePayload->name);
        self::assertSame('AAPL', $applePayload->symbol);
        self::assertSame('stock', $applePayload->iconName);
        self::assertSame('USD', $applePayload->currency);
        self::assertSame(201.0, $applePayload->currentValue);
        self::assertSame(0.5, $applePayload->changePercentage);
        self::assertSame('#00FF00', $applePayload->symbolColor?->hexCode);
        self::assertSame('#00FF00', $applePayload->sparklineColor?->hexCode);
        self::assertSame('41d', $applePayload->sparklinePeriod);

        self::assertSame('IWDA.AS', $worldEtfPayload->name);
        self::assertSame('IWDA', $worldEtfPayload->symbol);
        self::assertSame('etf', $worldEtfPayload->iconName);
        self::assertSame('EUR', $worldEtfPayload->currency);
        self::assertSame(99.0, $worldEtfPayload->currentValue);
        self::assertSame(-1.0, $worldEtfPayload->changePercentage);
        self::assertSame('#FF0000', $worldEtfPayload->symbolColor?->hexCode);
        self::assertSame('#FF0000', $worldEtfPayload->sparklineColor?->hexCode);
    }

    public function testEachPayloadDeclaresTheSilenceDerivedFromTheGroupInterval(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('twelvedata-time-series.json'), self::TWELVEDATA_BASE_URI));

        $trackerPayloads = $provider->fetchTrackers(self::syncInstant());

        self::assertSame(2700, $trackerPayloads[0]->staleAfterInSeconds);
        self::assertNull($trackerPayloads[0]->staleBehavior);
    }

    public function testEuropeanEtfSymbolIsShortenedToFitTheDeviceLimit(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('twelvedata-time-series.json'), self::TWELVEDATA_BASE_URI));

        [, $worldEtfPayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertSame('IWDA', $worldEtfPayload->symbol);
        self::assertSame('IWDA.AS', $worldEtfPayload->name);
    }

    public function testCurrencyStaysWithinTheDeviceLimit(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('twelvedata-time-series.json'), self::TWELVEDATA_BASE_URI));

        $trackerPayloads = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(2, $trackerPayloads);
        foreach ($trackerPayloads as $trackerPayload) {
            self::assertNotNull($trackerPayload->currency);
            self::assertLessThanOrEqual(self::MAXIMUM_CURRENCY_LENGTH, mb_strlen($trackerPayload->currency));
        }
    }

    public function testTheSparklineCarriesEveryDailyBarOfTheResponse(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('twelvedata-time-series.json'), self::TWELVEDATA_BASE_URI));

        [$applePayload, $worldEtfPayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(30, $applePayload->sparklinePoints);
        self::assertSame(172.0, $applePayload->sparklinePoints[0]);
        self::assertSame(201.0, $applePayload->sparklinePoints[29]);

        self::assertCount(30, $worldEtfPayload->sparklinePoints);
        self::assertSame(128.0, $worldEtfPayload->sparklinePoints[0]);
        self::assertSame(99.0, $worldEtfPayload->sparklinePoints[29]);
    }

    public function testTheVolumeSeriesCarriesTheVolumeOfEveryDailyBar(): void
    {
        $provider = $this->buildProvider(new MockHttpClient(self::fixtureResponse('twelvedata-time-series.json'), self::TWELVEDATA_BASE_URI));

        [$applePayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(30, $applePayload->volumePoints);
        self::assertSame(1000000.0, $applePayload->volumePoints[0]);
        self::assertSame(1029000.0, $applePayload->volumePoints[29]);
    }

    public function testAnIndexQuotedWithoutVolumeLeavesTheChartWithoutBars(): void
    {
        $provider = $this->buildProvider(
            new MockHttpClient(self::fixtureResponse('twelvedata-time-series-three-categories.json'), self::TWELVEDATA_BASE_URI),
            configFileName: self::THREE_CATEGORIES_CONFIG_FILE,
        );

        [$applePayload, , $indexPayload] = $provider->fetchTrackers(self::syncInstant());

        self::assertCount(6, $applePayload->volumePoints);
        self::assertSame([], $indexPayload->volumePoints);
    }

    public function testAllConfiguredItemsAreFetchedInASingleRequest(): void
    {
        $response = self::fixtureResponse('twelvedata-time-series.json');
        $httpClient = new MockHttpClient($response, self::TWELVEDATA_BASE_URI);

        $this->buildProvider($httpClient)->fetchTrackers(self::syncInstant());

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringStartsWith(self::TWELVEDATA_BASE_URI.'time_series?', $response->getRequestUrl());

        $queryParameters = self::queryParameters($response);
        self::assertSame('AAPL,IWDA.AS', $queryParameters['symbol'] ?? null);
        self::assertSame('1day', $queryParameters['interval'] ?? null);
        self::assertSame('30', $queryParameters['outputsize'] ?? null);
        self::assertSame('asc', $queryParameters['order'] ?? null);
    }

    public function testApiKeyTravelsInTheAuthorizationHeader(): void
    {
        $response = self::fixtureResponse('twelvedata-time-series.json');

        $this->buildProvider(new MockHttpClient($response, self::TWELVEDATA_BASE_URI), apiKey: 'demo-key')->fetchTrackers(self::syncInstant());

        self::assertContains('Authorization: apikey demo-key', self::requestHeaders($response));
        self::assertStringNotContainsString('demo-key', $response->getRequestUrl());
    }

    public function testMissingApiKeyMakesNoRequestAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger, apiKey: null)->fetchTrackers(self::syncInstant());

        self::assertSame([], $trackerPayloads);
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Twelve Data needs an API key'], array_column($logger->records, 'message'));
        self::assertSame('PIXELCAST_TWELVEDATA_API_KEY', $logger->records[0]['context']['environment_variable'] ?? null);
    }

    public function testSingleConfiguredItemHandlesTheUnkeyedResponseShape(): void
    {
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series-single-symbol.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, configFileName: self::SINGLE_ITEM_CONFIG_FILE)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('AAPL', $trackerPayloads[0]->symbol);
        self::assertSame('USD', $trackerPayloads[0]->currency);
        self::assertSame(201.0, $trackerPayloads[0]->currentValue);
    }

    public function testAConfiguredBottomTextReachesAPayloadThisProviderNeverFillsOnItsOwn(): void
    {
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series-single-symbol.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, configFileName: self::LABELLED_ITEM_CONFIG_FILE)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('Nasdaq', $trackerPayloads[0]->bottomText);
        self::assertSame('Apple', $trackerPayloads[0]->symbol);
        self::assertSame('#4CAF50', $trackerPayloads[0]->symbolColor?->hexCode);
    }

    public function testRequestLevelErrorYieldsNoTrackerAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series-request-error.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::syncInstant());

        self::assertSame([], $trackerPayloads);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Twelve Data rejected the request'], array_column($logger->records, 'message'));
        self::assertSame(429, $logger->records[0]['context']['code'] ?? null);
    }

    public function testSymbolLevelErrorIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series-symbol-error.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('AAPL', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Twelve Data could not serve a requested symbol'], array_column($logger->records, 'message'));
        self::assertSame('IWDA.AS', $logger->records[0]['context']['symbol'] ?? null);
    }

    public function testMissingSymbolInTheResponseIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series-apple-only.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('AAPL', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Twelve Data response is missing a requested symbol'], array_column($logger->records, 'message'));
        self::assertSame('IWDA.AS', $logger->records[0]['context']['symbol'] ?? null);
    }

    public function testSeriesTooShortToComputeAChangeIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series-malformed.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::syncInstant());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('IWDA', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Unexpected Twelve Data series shape'], array_column($logger->records, 'message'));
        self::assertSame('AAPL', $logger->records[0]['context']['symbol'] ?? null);
    }

    public function testTransportErrorYieldsNoTrackerAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::syncInstant());

        self::assertSame([], $trackerPayloads);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Twelve Data request failed'], array_column($logger->records, 'message'));
        self::assertSame('AAPL,IWDA.AS', $logger->records[0]['context']['symbols'] ?? null);
    }

    public function testCurrencyFallsBackToTheConfiguredValueWhenTheResponseOmitsIt(): void
    {
        $httpClient = new MockHttpClient(self::fixtureResponse('twelvedata-time-series-without-currency.json'), self::TWELVEDATA_BASE_URI);

        $trackerPayloads = $this->buildProvider($httpClient)->fetchTrackers(self::syncInstant());

        self::assertSame(
            ['USD', 'EUR'],
            array_map(static fn (TrackerPayload $trackerPayload): ?string => $trackerPayload->currency, $trackerPayloads),
        );
    }

    private function buildProvider(
        MockHttpClient $httpClient,
        ?LoggerInterface $logger = null,
        string $configFileName = self::TWO_ITEMS_CONFIG_FILE,
        ?string $apiKey = 'demo-key',
    ): TwelveDataTrackerProvider {
        return new TwelveDataTrackerProvider(
            $httpClient,
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
            $logger ?? new NullLogger(),
            $apiKey,
        );
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
            self::fail('The Twelve Data request carries no query string.');
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
            self::fail('The Twelve Data request carries no readable headers.');
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
