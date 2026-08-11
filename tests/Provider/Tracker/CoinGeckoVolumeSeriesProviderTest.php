<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\CoinGeckoVolumeSeriesProvider;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoinGeckoVolumeSeriesProviderTest extends TestCase
{
    private const string COINGECKO_BASE_URI = 'https://api.coingecko.com/api/v3/';
    private const int FIRST_POINT_TIMESTAMP = 1785967200000;

    public function testTheVolumeOfEveryPointIsReadInTheOrderItIsServed(): void
    {
        $provider = self::buildProvider(new MockHttpClient(self::volumesResponse([
            [self::FIRST_POINT_TIMESTAMP, 20162328086.19],
            [self::FIRST_POINT_TIMESTAMP + 3600000, 17283014395.61],
        ]), self::COINGECKO_BASE_URI));

        self::assertSame([20162328086.19, 17283014395.61], $provider->volumeSeriesOf('bitcoin', 'usd'));
    }

    /**
     * The curve of a tracker comes from sparkline_in_7d, so a window of another length would put
     * the bars over the wrong hours however well the two series are aligned afterwards.
     */
    public function testTheRequestedWindowIsTheSameSevenDaysAsThePriceCurve(): void
    {
        $response = self::volumesResponse([[self::FIRST_POINT_TIMESTAMP, 20162328086.19]]);

        self::buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI))->volumeSeriesOf('bitcoin', 'usd');

        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringStartsWith(self::COINGECKO_BASE_URI.'coins/bitcoin/market_chart?', $response->getRequestUrl());

        $queryParameters = self::queryParameters($response);
        self::assertSame('usd', $queryParameters['vs_currency'] ?? null);
        self::assertSame('7', $queryParameters['days'] ?? null);
    }

    public function testTheSameCoinIsFetchedOnlyOnceWithinTheCacheLifetime(): void
    {
        $httpClient = new MockHttpClient(
            [self::volumesResponse([[self::FIRST_POINT_TIMESTAMP, 20162328086.19]]), new MockResponse('', ['error' => 'a second call would fail'])],
            self::COINGECKO_BASE_URI,
        );
        $provider = self::buildProvider($httpClient);

        self::assertSame([20162328086.19], $provider->volumeSeriesOf('bitcoin', 'usd'));
        self::assertSame([20162328086.19], $provider->volumeSeriesOf('bitcoin', 'usd'));
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testEachCurrencyIsFetchedOnItsOwn(): void
    {
        $httpClient = new MockHttpClient(
            [
                self::volumesResponse([[self::FIRST_POINT_TIMESTAMP, 20162328086.19]]),
                self::volumesResponse([[self::FIRST_POINT_TIMESTAMP, 17283014395.61]]),
            ],
            self::COINGECKO_BASE_URI,
        );
        $provider = self::buildProvider($httpClient);

        self::assertSame([20162328086.19], $provider->volumeSeriesOf('bitcoin', 'usd'));
        self::assertSame([17283014395.61], $provider->volumeSeriesOf('bitcoin', 'eur'));
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testAFailedFetchIsNotCachedAndIsRetriedOnTheNextCall(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(
            [new MockResponse('', ['error' => 'connection refused']), self::volumesResponse([[self::FIRST_POINT_TIMESTAMP, 20162328086.19]])],
            self::COINGECKO_BASE_URI,
        );
        $provider = self::buildProvider($httpClient, $logger);

        self::assertSame([], $provider->volumeSeriesOf('bitcoin', 'usd'));
        self::assertSame([20162328086.19], $provider->volumeSeriesOf('bitcoin', 'usd'));

        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['CoinGecko volume series request failed'], array_column($logger->records, 'message'));
    }

    public function testAResponseWithoutTheVolumeSeriesIsReportedAsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $provider = self::buildProvider(new MockHttpClient(new MockResponse('{"prices":[]}'), self::COINGECKO_BASE_URI), $logger);

        self::assertSame([], $provider->volumeSeriesOf('bitcoin', 'usd'));
        self::assertSame(['CoinGecko served no usable volume series'], array_column($logger->records, 'message'));
        self::assertSame('bitcoin', $logger->records[0]['context']['coin_id'] ?? null);
    }

    public function testAPointWithoutAReadableVolumeDropsTheWholeSeries(): void
    {
        $logger = new RecordingLoggerStub();
        $provider = self::buildProvider(new MockHttpClient(self::volumesResponse([
            [self::FIRST_POINT_TIMESTAMP, 20162328086.19],
            [self::FIRST_POINT_TIMESTAMP + 3600000],
        ]), self::COINGECKO_BASE_URI), $logger);

        self::assertSame([], $provider->volumeSeriesOf('bitcoin', 'usd'));
        self::assertSame(['CoinGecko served no usable volume series'], array_column($logger->records, 'message'));
    }

    public function testApiKeyIsSentAsHeaderWhenConfigured(): void
    {
        $response = self::volumesResponse([[self::FIRST_POINT_TIMESTAMP, 20162328086.19]]);

        self::buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI), apiKey: 'demo-key')->volumeSeriesOf('bitcoin', 'usd');

        $headers = $response->getRequestOptions()['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertContains('x-cg-demo-api-key: demo-key', $headers);
    }

    private static function buildProvider(
        MockHttpClient $httpClient,
        ?LoggerInterface $logger = null,
        ?string $apiKey = null,
    ): CoinGeckoVolumeSeriesProvider {
        return new CoinGeckoVolumeSeriesProvider(
            $httpClient,
            new ArrayAdapter(),
            $logger ?? new NullLogger(),
            $apiKey,
        );
    }

    /**
     * @param list<array<int, float|int>> $volumePoints
     */
    private static function volumesResponse(array $volumePoints): MockResponse
    {
        return new MockResponse(json_encode(['total_volumes' => $volumePoints], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function queryParameters(MockResponse $response): array
    {
        $queryString = parse_url($response->getRequestUrl(), \PHP_URL_QUERY);
        if (!\is_string($queryString)) {
            self::fail('The CoinGecko volume series request carries no query string.');
        }

        parse_str($queryString, $queryParameters);

        return $queryParameters;
    }
}
