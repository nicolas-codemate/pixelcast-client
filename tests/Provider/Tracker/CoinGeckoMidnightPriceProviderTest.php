<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\CoinGeckoMidnightPriceProvider;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoinGeckoMidnightPriceProviderTest extends TestCase
{
    private const string COINGECKO_BASE_URI = 'https://api.coingecko.com/api/v3/';
    private const string PARIS_TIMEZONE = 'Europe/Paris';
    private const string MORNING_OF_A_SUMMER_DAY = '2026-08-06 09:30:00';
    // Midnight in Paris on that summer day, when the offset to UTC is two hours.
    private const int MIDNIGHT_TIMESTAMP = 1785967200;
    private const float MIDNIGHT_PRICE = 64680.67;

    public function testTheEarliestPriceOfTheDayIsReturned(): void
    {
        $provider = self::buildProvider(new MockHttpClient(self::pricesResponse([
            [self::MIDNIGHT_TIMESTAMP * 1000, self::MIDNIGHT_PRICE],
            [(self::MIDNIGHT_TIMESTAMP + 300) * 1000, 64626.67],
        ]), self::COINGECKO_BASE_URI));

        self::assertSame(self::MIDNIGHT_PRICE, $provider->priceAtMidnightOf('bitcoin', 'usd'));
    }

    public function testTheRequestedWindowStartsAtTheLastParisMidnight(): void
    {
        $response = self::pricesResponse([[self::MIDNIGHT_TIMESTAMP * 1000, self::MIDNIGHT_PRICE]]);

        self::buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI))->priceAtMidnightOf('bitcoin', 'usd');

        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringStartsWith(self::COINGECKO_BASE_URI.'coins/bitcoin/market_chart/range?', $response->getRequestUrl());

        $queryParameters = self::queryParameters($response);
        self::assertSame('usd', $queryParameters['vs_currency'] ?? null);
        self::assertSame((string) self::MIDNIGHT_TIMESTAMP, $queryParameters['from'] ?? null);
        self::assertSame((string) (self::MIDNIGHT_TIMESTAMP + 3600), $queryParameters['to'] ?? null);

        $midnightInParis = new \DateTimeImmutable('@'.self::MIDNIGHT_TIMESTAMP)->setTimezone(new \DateTimeZone(self::PARIS_TIMEZONE));
        self::assertSame('2026-08-06 00:00:00', $midnightInParis->format('Y-m-d H:i:s'));
    }

    public function testTheSameDayIsLookedUpOnlyOnce(): void
    {
        $httpClient = new MockHttpClient(
            [self::pricesResponse([[self::MIDNIGHT_TIMESTAMP * 1000, self::MIDNIGHT_PRICE]]), new MockResponse('', ['error' => 'a second call would fail'])],
            self::COINGECKO_BASE_URI,
        );
        $provider = self::buildProvider($httpClient);

        self::assertSame(self::MIDNIGHT_PRICE, $provider->priceAtMidnightOf('bitcoin', 'usd'));
        self::assertSame(self::MIDNIGHT_PRICE, $provider->priceAtMidnightOf('bitcoin', 'usd'));
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testEachCurrencyIsLookedUpOnItsOwn(): void
    {
        $httpClient = new MockHttpClient(
            [
                self::pricesResponse([[self::MIDNIGHT_TIMESTAMP * 1000, self::MIDNIGHT_PRICE]]),
                self::pricesResponse([[self::MIDNIGHT_TIMESTAMP * 1000, 55123.45]]),
            ],
            self::COINGECKO_BASE_URI,
        );
        $provider = self::buildProvider($httpClient);

        self::assertSame(self::MIDNIGHT_PRICE, $provider->priceAtMidnightOf('bitcoin', 'usd'));
        self::assertSame(55123.45, $provider->priceAtMidnightOf('bitcoin', 'eur'));
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testAFailedLookupIsNotCachedAndIsRetriedOnTheNextCall(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(
            [new MockResponse('', ['error' => 'connection refused']), self::pricesResponse([[self::MIDNIGHT_TIMESTAMP * 1000, self::MIDNIGHT_PRICE]])],
            self::COINGECKO_BASE_URI,
        );
        $provider = self::buildProvider($httpClient, $logger);

        self::assertNull($provider->priceAtMidnightOf('bitcoin', 'usd'));
        self::assertSame(self::MIDNIGHT_PRICE, $provider->priceAtMidnightOf('bitcoin', 'usd'));

        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['CoinGecko midnight price request failed'], array_column($logger->records, 'message'));
    }

    public function testAnEmptyWindowIsReportedAsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $provider = self::buildProvider(new MockHttpClient(self::pricesResponse([]), self::COINGECKO_BASE_URI), $logger);

        self::assertNull($provider->priceAtMidnightOf('bitcoin', 'usd'));
        self::assertSame(['CoinGecko served no usable price around midnight'], array_column($logger->records, 'message'));
    }

    public function testAPriceTooFarFromMidnightIsRefused(): void
    {
        $logger = new RecordingLoggerStub();
        $tooLatePricePoint = [(self::MIDNIGHT_TIMESTAMP + 1800) * 1000, self::MIDNIGHT_PRICE];
        $provider = self::buildProvider(new MockHttpClient(self::pricesResponse([$tooLatePricePoint]), self::COINGECKO_BASE_URI), $logger);

        self::assertNull($provider->priceAtMidnightOf('bitcoin', 'usd'));
        self::assertSame(['The earliest CoinGecko price of the day is too far from midnight'], array_column($logger->records, 'message'));
        self::assertSame(1800, $logger->records[0]['context']['distance_in_seconds'] ?? null);
    }

    public function testApiKeyIsSentAsHeaderWhenConfigured(): void
    {
        $response = self::pricesResponse([[self::MIDNIGHT_TIMESTAMP * 1000, self::MIDNIGHT_PRICE]]);

        self::buildProvider(new MockHttpClient($response, self::COINGECKO_BASE_URI), apiKey: 'demo-key')->priceAtMidnightOf('bitcoin', 'usd');

        $headers = $response->getRequestOptions()['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertContains('x-cg-demo-api-key: demo-key', $headers);
    }

    private static function buildProvider(
        MockHttpClient $httpClient,
        ?LoggerInterface $logger = null,
        ?string $apiKey = null,
    ): CoinGeckoMidnightPriceProvider {
        return new CoinGeckoMidnightPriceProvider(
            $httpClient,
            new ArrayAdapter(),
            new MockClock(self::MORNING_OF_A_SUMMER_DAY, self::PARIS_TIMEZONE),
            $logger ?? new NullLogger(),
            $apiKey,
        );
    }

    /**
     * @param list<array{int, float}> $pricePoints
     */
    private static function pricesResponse(array $pricePoints): MockResponse
    {
        return new MockResponse(json_encode(['prices' => $pricePoints], \JSON_THROW_ON_ERROR));
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
}
