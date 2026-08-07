<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The price a coin was worth at the last midnight, the fixed reference a daily change is measured against.
 */
final readonly class CoinGeckoMidnightPriceProvider
{
    private const string MARKET_CHART_RANGE_PATH_TEMPLATE = 'coins/%s/market_chart/range';
    private const string API_KEY_HEADER = 'x-cg-demo-api-key';
    // The device sits in a French home, so a day starts at local midnight, not at the UTC candle open.
    private const string REFERENCE_TIMEZONE = 'Europe/Paris';
    private const int LOOKUP_WINDOW_IN_SECONDS = 3600;
    private const int MAXIMUM_DISTANCE_TO_MIDNIGHT_IN_SECONDS = 900;
    private const string CACHE_KEY_PREFIX = 'coingecko_midnight_price_';
    private const string CACHE_KEY_DATE_FORMAT = 'Y-m-d';

    public function __construct(
        #[Target('coingecko.client')]
        private HttpClientInterface $coinGeckoClient,
        private CacheInterface $cache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
    ) {
    }

    public function priceAtMidnightOf(string $coinId, string $currency): ?float
    {
        $midnight = $this->lastMidnight();

        return $this->cache->get(
            self::buildCacheKey($coinId, $currency, $midnight),
            function (ItemInterface $cacheItem, bool &$shouldSave) use ($coinId, $currency, $midnight): ?float {
                // A lifetime, not a date: the cache pool compares expiry to the system clock,
                // which an injected clock is free to disagree with.
                $cacheItem->expiresAfter($midnight->modify('+1 day')->getTimestamp() - $this->clock->now()->getTimestamp());

                $midnightPrice = $this->requestPriceAtMidnight($coinId, $currency, $midnight);
                if (null === $midnightPrice) {
                    // The price of a past midnight never changes: only a failed lookup is worth retrying.
                    $shouldSave = false;
                }

                return $midnightPrice;
            },
        );
    }

    private function lastMidnight(): \DateTimeImmutable
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::REFERENCE_TIMEZONE))
            ->setTime(0, 0);
    }

    private static function buildCacheKey(string $coinId, string $currency, \DateTimeImmutable $midnight): string
    {
        return self::CACHE_KEY_PREFIX.\sprintf('%s_%s_%s', $coinId, $currency, $midnight->format(self::CACHE_KEY_DATE_FORMAT));
    }

    private function requestPriceAtMidnight(string $coinId, string $currency, \DateTimeImmutable $midnight): ?float
    {
        $headers = [];
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers[self::API_KEY_HEADER] = $this->apiKey;
        }

        try {
            /** @var array<string, mixed> $decodedChart */
            $decodedChart = $this->coinGeckoClient->request('GET', \sprintf(self::MARKET_CHART_RANGE_PATH_TEMPLATE, $coinId), [
                'headers' => $headers,
                'query' => [
                    'vs_currency' => $currency,
                    'from' => $midnight->getTimestamp(),
                    'to' => $midnight->getTimestamp() + self::LOOKUP_WINDOW_IN_SECONDS,
                ],
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('CoinGecko midnight price request failed', [
                'coin_id' => $coinId,
                'currency' => $currency,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }

        return $this->readEarliestPrice($decodedChart, $coinId, $currency, $midnight);
    }

    /**
     * @param array<string, mixed> $decodedChart
     */
    private function readEarliestPrice(array $decodedChart, string $coinId, string $currency, \DateTimeImmutable $midnight): ?float
    {
        $pricePoints = $decodedChart['prices'] ?? null;
        $earliestPricePoint = \is_array($pricePoints) ? reset($pricePoints) : false;

        if (!\is_array($earliestPricePoint) || !is_numeric($earliestPricePoint[0] ?? null) || !is_numeric($earliestPricePoint[1] ?? null)) {
            $this->logger->warning('CoinGecko served no usable price around midnight', [
                'coin_id' => $coinId,
                'currency' => $currency,
            ]);

            return null;
        }

        $distanceToMidnightInSeconds = (int) ((float) $earliestPricePoint[0] / 1000) - $midnight->getTimestamp();
        if ($distanceToMidnightInSeconds > self::MAXIMUM_DISTANCE_TO_MIDNIGHT_IN_SECONDS) {
            $this->logger->warning('The earliest CoinGecko price of the day is too far from midnight', [
                'coin_id' => $coinId,
                'currency' => $currency,
                'distance_in_seconds' => $distanceToMidnightInSeconds,
            ]);

            return null;
        }

        return (float) $earliestPricePoint[1];
    }
}
