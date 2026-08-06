<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Provider\Tracker\CoinGeckoMidnightPriceProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Serves the midnight prices the tracker fixtures are meant to be compared against,
 * whatever day the test runs on: the served point always sits on the requested midnight.
 */
final class CoinGeckoMidnightPriceProviderFactory
{
    public const float BITCOIN_MIDNIGHT_PRICE = 44000.0;
    public const float ETHEREUM_MIDNIGHT_PRICE = 2550.0;

    private const string COINGECKO_BASE_URI = 'https://api.coingecko.com/api/v3/';
    private const string COIN_ID_PATTERN = '#/coins/([^/]+)/market_chart/range#';
    private const array MIDNIGHT_PRICES_BY_COIN_ID = [
        'bitcoin' => self::BITCOIN_MIDNIGHT_PRICE,
        'ethereum' => self::ETHEREUM_MIDNIGHT_PRICE,
    ];

    public static function withFixturePrices(?LoggerInterface $logger = null): CoinGeckoMidnightPriceProvider
    {
        return self::withHttpClient(new MockHttpClient(self::respondWithMidnightPrice(...), self::COINGECKO_BASE_URI), $logger);
    }

    public static function withHttpClient(MockHttpClient $httpClient, ?LoggerInterface $logger = null): CoinGeckoMidnightPriceProvider
    {
        return new CoinGeckoMidnightPriceProvider(
            $httpClient,
            new ArrayAdapter(),
            new MockClock(),
            $logger ?? new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function respondWithMidnightPrice(string $method, string $url, array $options = []): MockResponse
    {
        $midnightPrice = self::MIDNIGHT_PRICES_BY_COIN_ID[self::coinIdOf($url)] ?? null;
        if (null === $midnightPrice) {
            return new MockResponse(json_encode(['prices' => []], \JSON_THROW_ON_ERROR));
        }

        $pricePoint = [self::requestedWindowStartInMilliseconds($url), $midnightPrice];

        return new MockResponse(json_encode(['prices' => [$pricePoint]], \JSON_THROW_ON_ERROR));
    }

    private static function coinIdOf(string $url): string
    {
        return 1 === preg_match(self::COIN_ID_PATTERN, $url, $matches) ? $matches[1] : '';
    }

    private static function requestedWindowStartInMilliseconds(string $url): int
    {
        $queryString = parse_url($url, \PHP_URL_QUERY);
        parse_str(\is_string($queryString) ? $queryString : '', $queryParameters);

        $windowStart = $queryParameters['from'] ?? null;

        return is_numeric($windowStart) ? (int) $windowStart * 1000 : 0;
    }
}
