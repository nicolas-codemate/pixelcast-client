<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Client\StaleBehavior;
use App\Provider\Tracker\BoursoramaTrackerProvider;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoursoramaTrackerProviderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string BOURSORAMA_BASE_URI = 'https://www.boursorama.com/';
    private const string TWO_ITEMS_CONFIG_FILE = 'pixelcast-boursorama.yaml';
    private const string PLAIN_CODE_CONFIG_FILE = 'pixelcast-boursorama-plain-code.yaml';
    private const string LABELLED_ITEM_CONFIG_FILE = 'pixelcast-boursorama-labelled-item.yaml';
    private const string TWO_MARKETS_CONFIG_FILE = 'pixelcast-boursorama-two-markets.yaml';
    private const string MARKET_TIMEZONE = 'Europe/Paris';
    private const string INSTANT_INSIDE_EVERY_WINDOW = '2026-08-03 16:00:00';
    private const string INSTANT_BEFORE_THE_AMERICAN_OPENING = '2026-08-03 10:00:00';

    public function testFetchTrackersBuildsPayloadsFromFixture(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        $trackerPayloads = $provider->fetchTrackers(self::insideEveryWindow());

        self::assertCount(2, $trackerPayloads);

        [$worldEtfPayload, $secondWorldEtfPayload] = $trackerPayloads;

        self::assertSame('1RTDCAM', $worldEtfPayload->name);
        self::assertSame('DCAM', $worldEtfPayload->symbol);
        self::assertSame('etf', $worldEtfPayload->iconName);
        self::assertSame('EUR', $worldEtfPayload->currency);
        self::assertSame(6.262, $worldEtfPayload->currentValue);
        self::assertSame(1.0, $worldEtfPayload->changePercentage);
        self::assertSame('#00FF00', $worldEtfPayload->symbolColor?->hexCode);
        self::assertSame('#00FF00', $worldEtfPayload->sparklineColor?->hexCode);
        self::assertSame('33d', $worldEtfPayload->sparklinePeriod);

        self::assertSame('1RTCW8', $secondWorldEtfPayload->name);
        self::assertSame('CW8', $secondWorldEtfPayload->symbol);
        self::assertSame(495.0, $secondWorldEtfPayload->currentValue);
        self::assertSame(-1.0, $secondWorldEtfPayload->changePercentage);
        self::assertSame('#FF0000', $secondWorldEtfPayload->symbolColor?->hexCode);
        self::assertSame('#FF0000', $secondWorldEtfPayload->sparklineColor?->hexCode);
    }

    public function testEachPayloadDeclaresTheSilenceDerivedFromTheGroupInterval(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        $trackerPayloads = $provider->fetchTrackers(self::insideEveryWindow());

        self::assertSame(2700, $trackerPayloads[0]->staleAfterInSeconds);
        self::assertNull($trackerPayloads[0]->staleBehavior);
    }

    public function testEachConfiguredItemTriggersItsOwnRequest(): void
    {
        $firstResponse = self::fixtureResponse('boursorama-ticks-dcam.json');
        $secondResponse = self::fixtureResponse('boursorama-ticks-cw8.json');
        $httpClient = new MockHttpClient([$firstResponse, $secondResponse], self::BOURSORAMA_BASE_URI);

        $this->buildProvider($httpClient)->fetchTrackers(self::insideEveryWindow());

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertSame('GET', $firstResponse->getRequestMethod());
        self::assertStringStartsWith(self::BOURSORAMA_BASE_URI.'bourse/action/graph/ws/GetTicksEOD?', $firstResponse->getRequestUrl());

        $queryParameters = self::queryParameters($firstResponse);
        self::assertSame('1rTDCAM', $queryParameters['symbol'] ?? null);
        self::assertSame('30', $queryParameters['length'] ?? null);
        self::assertSame('0', $queryParameters['period'] ?? null);
        self::assertArrayHasKey('guid', $queryParameters);
        self::assertSame('', $queryParameters['guid']);

        self::assertSame('1rTCW8', self::queryParameters($secondResponse)['symbol'] ?? null);
    }

    public function testTheHeadersTheEndpointRequiresTravelWithTheRequest(): void
    {
        $response = self::fixtureResponse('boursorama-ticks-dcam.json');
        $httpClient = new MockHttpClient([$response, self::fixtureResponse('boursorama-ticks-cw8.json')], self::BOURSORAMA_BASE_URI);

        $this->buildProvider($httpClient)->fetchTrackers(self::insideEveryWindow());

        $requestHeaders = self::requestHeaders($response);
        self::assertContains('X-Requested-With: XMLHttpRequest', $requestHeaders);
        self::assertStringContainsString('User-Agent: Mozilla/', implode("\n", $requestHeaders));
    }

    public function testACodeWithoutTheExpectedPrefixIsDisplayedWhole(): void
    {
        $provider = $this->buildProvider(
            $this->fixtureClient('boursorama-ticks-dcam.json'),
            configFileName: self::PLAIN_CODE_CONFIG_FILE,
        );

        $trackerPayloads = $provider->fetchTrackers(self::insideEveryWindow());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('DCAM', $trackerPayloads[0]->symbol);
        self::assertSame('DCAM', $trackerPayloads[0]->name);
    }

    public function testAConfiguredLabelColorAndBottomTextWinOverTheDerivedOnes(): void
    {
        $provider = $this->buildProvider(
            $this->fixtureClient('boursorama-ticks-dcam.json'),
            configFileName: self::LABELLED_ITEM_CONFIG_FILE,
        );

        $trackerPayloads = $provider->fetchTrackers(self::insideEveryWindow());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('Amundi PEA Monde', $trackerPayloads[0]->symbol);
        self::assertSame('1RTDCAM', $trackerPayloads[0]->name);
        self::assertSame('#4CAF50', $trackerPayloads[0]->symbolColor?->hexCode);
        self::assertSame('#00FF00', $trackerPayloads[0]->sparklineColor?->hexCode);
        self::assertSame('MSCI World', $trackerPayloads[0]->bottomText);
    }

    public function testTheSparklineCarriesEveryClosingPriceOfTheResponse(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        [$worldEtfPayload] = $provider->fetchTrackers(self::insideEveryWindow());

        self::assertCount(24, $worldEtfPayload->sparklinePoints);
        self::assertSame(6.0, $worldEtfPayload->sparklinePoints[0]);
        self::assertSame(6.262, $worldEtfPayload->sparklinePoints[23]);
    }

    public function testTheSparklinePeriodStatesTheCalendarSpannedRatherThanTheCloseCount(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        [$worldEtfPayload] = $provider->fetchTrackers(self::insideEveryWindow());

        self::assertCount(24, $worldEtfPayload->sparklinePoints);
        self::assertSame('33d', $worldEtfPayload->sparklinePeriod);
    }

    public function testAnUnknownCodeIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = $this->fixtureClient('boursorama-ticks-empty.json', 'boursorama-ticks-cw8.json');

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::insideEveryWindow());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('CW8', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Boursorama served no quotes for a symbol'], array_column($logger->records, 'message'));
        self::assertSame('1rTDCAM', $logger->records[0]['context']['symbol'] ?? null);
    }

    public function testASeriesTooShortToComputeAChangeIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = $this->fixtureClient('boursorama-ticks-single-bar.json', 'boursorama-ticks-cw8.json');

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::insideEveryWindow());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('CW8', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Unexpected Boursorama quote series shape'], array_column($logger->records, 'message'));
        self::assertSame('1rTDCAM', $logger->records[0]['context']['symbol'] ?? null);
        self::assertSame(1, $logger->records[0]['context']['bar_count'] ?? null);
    }

    public function testAnAssetWhoseOwnWindowIsClosedCostsNoRequest(): void
    {
        $httpClient = $this->fixtureClient('boursorama-ticks-dcam.json');

        $trackerPayloads = $this->buildProvider($httpClient, configFileName: self::TWO_MARKETS_CONFIG_FILE)
            ->fetchTrackers(self::marketInstant(self::INSTANT_BEFORE_THE_AMERICAN_OPENING));

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertCount(1, $trackerPayloads);
        self::assertSame('DCAM', $trackerPayloads[0]->symbol);
    }

    public function testWithoutAnInstantToJudgeTheWindowsEveryAssetIsFetched(): void
    {
        $httpClient = $this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json');

        $trackerPayloads = $this->buildProvider($httpClient, configFileName: self::TWO_MARKETS_CONFIG_FILE)->fetchTrackers(null);

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertCount(2, $trackerPayloads);
        self::assertSame('DCAM', $trackerPayloads[0]->symbol);
        self::assertSame('CW8', $trackerPayloads[1]->symbol);
    }

    public function testEveryAssetIsFetchedOnceTheirTwoWindowsOverlap(): void
    {
        $httpClient = $this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json');

        $trackerPayloads = $this->buildProvider($httpClient, configFileName: self::TWO_MARKETS_CONFIG_FILE)->fetchTrackers(self::insideEveryWindow());

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertCount(2, $trackerPayloads);
        self::assertSame('DCAM', $trackerPayloads[0]->symbol);
        self::assertSame('CW8', $trackerPayloads[1]->symbol);
    }

    public function testAnAssetFollowsTheFreshnessOfItsGroupUnlessItDeclaresItsOwn(): void
    {
        $provider = $this->buildProvider(
            $this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'),
            configFileName: self::TWO_MARKETS_CONFIG_FILE,
        );

        [$inheritingPayload, $overridingPayload] = $provider->fetchTrackers(self::insideEveryWindow());

        self::assertSame(2700, $inheritingPayload->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $inheritingPayload->staleBehavior);
        self::assertSame(5400, $overridingPayload->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Dim, $overridingPayload->staleBehavior);
    }

    public function testATransportErrorOnOneAssetLeavesTheOthersUntouched(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(
            [new MockResponse('', ['error' => 'connection refused']), self::fixtureResponse('boursorama-ticks-cw8.json')],
            self::BOURSORAMA_BASE_URI,
        );

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers(self::insideEveryWindow());

        self::assertCount(1, $trackerPayloads);
        self::assertSame('CW8', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Boursorama request failed'], array_column($logger->records, 'message'));
        self::assertSame('1rTDCAM', $logger->records[0]['context']['symbol'] ?? null);
        self::assertNotNull($logger->records[0]['context']['error'] ?? null);
    }

    private function buildProvider(
        MockHttpClient $httpClient,
        ?LoggerInterface $logger = null,
        string $configFileName = self::TWO_ITEMS_CONFIG_FILE,
    ): BoursoramaTrackerProvider {
        return new BoursoramaTrackerProvider(
            $httpClient,
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
            $logger ?? new NullLogger(),
        );
    }

    private static function insideEveryWindow(): \DateTimeImmutable
    {
        return self::marketInstant(self::INSTANT_INSIDE_EVERY_WINDOW);
    }

    private static function marketInstant(string $instant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instant, new \DateTimeZone(self::MARKET_TIMEZONE));
    }

    private function fixtureClient(string ...$fixtureFileNames): MockHttpClient
    {
        return new MockHttpClient(array_map(self::fixtureResponse(...), $fixtureFileNames), self::BOURSORAMA_BASE_URI);
    }

    private static function fixtureResponse(string $fixtureFileName): MockResponse
    {
        return MockResponse::fromFile(self::FIXTURES_DIR.'/'.$fixtureFileName);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function queryParameters(MockResponse $response): array
    {
        $queryString = parse_url($response->getRequestUrl(), \PHP_URL_QUERY);
        if (!\is_string($queryString)) {
            self::fail('The Boursorama request carries no query string.');
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
            self::fail('The Boursorama request carries no readable headers.');
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
