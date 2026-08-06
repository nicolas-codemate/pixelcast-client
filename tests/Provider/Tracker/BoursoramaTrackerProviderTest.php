<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
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
    private const int MAXIMUM_SYMBOL_LENGTH = 7;

    public function testFetchTrackersBuildsPayloadsFromFixture(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        $trackerPayloads = $provider->fetchTrackers();

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
        self::assertSame('24d', $worldEtfPayload->sparklinePeriod);

        self::assertSame('1RTCW8', $secondWorldEtfPayload->name);
        self::assertSame('CW8', $secondWorldEtfPayload->symbol);
        self::assertSame(495.0, $secondWorldEtfPayload->currentValue);
        self::assertSame(-1.0, $secondWorldEtfPayload->changePercentage);
        self::assertSame('#FF0000', $secondWorldEtfPayload->symbolColor?->hexCode);
        self::assertSame('#FF0000', $secondWorldEtfPayload->sparklineColor?->hexCode);
    }

    public function testEachConfiguredItemTriggersItsOwnRequest(): void
    {
        $firstResponse = self::fixtureResponse('boursorama-ticks-dcam.json');
        $secondResponse = self::fixtureResponse('boursorama-ticks-cw8.json');
        $httpClient = new MockHttpClient([$firstResponse, $secondResponse], self::BOURSORAMA_BASE_URI);

        $this->buildProvider($httpClient)->fetchTrackers();

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

    public function testTheBoursoramaPrefixIsStrippedFromTheDisplayedSymbol(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        $trackerPayloads = $provider->fetchTrackers();

        self::assertSame(
            ['DCAM', 'CW8'],
            array_map(static fn (TrackerPayload $trackerPayload): ?string => $trackerPayload->symbol, $trackerPayloads),
        );
        self::assertSame(
            ['1RTDCAM', '1RTCW8'],
            array_map(static fn (TrackerPayload $trackerPayload): string => $trackerPayload->name, $trackerPayloads),
        );
    }

    public function testACodeWithoutTheExpectedPrefixIsDisplayedWhole(): void
    {
        $provider = $this->buildProvider(
            $this->fixtureClient('boursorama-ticks-dcam.json'),
            configFileName: self::PLAIN_CODE_CONFIG_FILE,
        );

        $trackerPayloads = $provider->fetchTrackers();

        self::assertCount(1, $trackerPayloads);
        self::assertSame('DCAM', $trackerPayloads[0]->symbol);
        self::assertSame('DCAM', $trackerPayloads[0]->name);
    }

    public function testTheDisplayedSymbolStaysWithinTheDeviceLimit(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        $trackerPayloads = $provider->fetchTrackers();

        self::assertCount(2, $trackerPayloads);
        foreach ($trackerPayloads as $trackerPayload) {
            self::assertNotNull($trackerPayload->symbol);
            self::assertLessThanOrEqual(self::MAXIMUM_SYMBOL_LENGTH, mb_strlen($trackerPayload->symbol));
        }
    }

    public function testTheSparklineCarriesEveryClosingPriceOfTheResponse(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        [$worldEtfPayload] = $provider->fetchTrackers();

        self::assertCount(24, $worldEtfPayload->sparklinePoints);
        self::assertSame(6.0, $worldEtfPayload->sparklinePoints[0]);
        self::assertSame(6.262, $worldEtfPayload->sparklinePoints[23]);
        self::assertSame('24d', $worldEtfPayload->sparklinePeriod);
    }

    public function testTheCurrencyComesFromTheConfigurationFile(): void
    {
        $provider = $this->buildProvider($this->fixtureClient('boursorama-ticks-dcam.json', 'boursorama-ticks-cw8.json'));

        $trackerPayloads = $provider->fetchTrackers();

        self::assertSame(
            ['EUR', 'EUR'],
            array_map(static fn (TrackerPayload $trackerPayload): ?string => $trackerPayload->currency, $trackerPayloads),
        );
    }

    public function testAnUnknownCodeIsSkippedAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = $this->fixtureClient('boursorama-ticks-empty.json', 'boursorama-ticks-cw8.json');

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers();

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

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers();

        self::assertCount(1, $trackerPayloads);
        self::assertSame('CW8', $trackerPayloads[0]->symbol);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Unexpected Boursorama quote series shape'], array_column($logger->records, 'message'));
        self::assertSame('1rTDCAM', $logger->records[0]['context']['symbol'] ?? null);
        self::assertSame(1, $logger->records[0]['context']['bar_count'] ?? null);
    }

    public function testATransportErrorOnOneAssetLeavesTheOthersUntouched(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(
            [new MockResponse('', ['error' => 'connection refused']), self::fixtureResponse('boursorama-ticks-cw8.json')],
            self::BOURSORAMA_BASE_URI,
        );

        $trackerPayloads = $this->buildProvider($httpClient, $logger)->fetchTrackers();

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

    private function fixtureClient(string ...$fixtureFileNames): MockHttpClient
    {
        return new MockHttpClient(array_map(self::fixtureResponse(...), $fixtureFileNames), self::BOURSORAMA_BASE_URI);
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
            self::fail('The Boursorama request carries no query string.');
        }

        parse_str($queryString, $queryParameters);

        return $queryParameters;
    }
}
