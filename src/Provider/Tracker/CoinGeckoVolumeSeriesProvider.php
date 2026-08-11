<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The volumes a coin traded over the week its price curve covers. The markets endpoint that
 * serves the curve only carries the volume of the last 24 hours, as a single figure, so the
 * series takes a request of its own.
 */
final readonly class CoinGeckoVolumeSeriesProvider
{
    private const string MARKET_CHART_PATH_TEMPLATE = 'coins/%s/market_chart';
    private const string API_KEY_HEADER = 'x-cg-demo-api-key';
    // The tracker curve comes from sparkline_in_7d, so the volumes cover the same seven days.
    private const int CHART_WINDOW_IN_DAYS = 7;
    // A seven day chart is sampled hourly, so an hour is as often as the series can change.
    private const int CACHE_LIFETIME_IN_SECONDS = 3600;
    private const string CACHE_KEY_PREFIX = 'coingecko_volume_series_';

    public function __construct(
        #[Target('coingecko.client')]
        private HttpClientInterface $coinGeckoClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
    ) {
    }

    /**
     * @return list<float> oldest first, empty when the source served nothing usable
     */
    public function volumeSeriesOf(string $coinId, string $currency): array
    {
        return $this->cache->get(
            self::buildCacheKey($coinId, $currency),
            function (ItemInterface $cacheItem, bool &$shouldSave) use ($coinId, $currency): array {
                $cacheItem->expiresAfter(self::CACHE_LIFETIME_IN_SECONDS);

                $volumeSeries = $this->requestVolumeSeries($coinId, $currency);
                if ([] === $volumeSeries) {
                    $shouldSave = false;
                }

                return $volumeSeries;
            },
        );
    }

    private static function buildCacheKey(string $coinId, string $currency): string
    {
        return self::CACHE_KEY_PREFIX.\sprintf('%s_%s', $coinId, $currency);
    }

    /**
     * @return list<float>
     */
    private function requestVolumeSeries(string $coinId, string $currency): array
    {
        $headers = [];
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers[self::API_KEY_HEADER] = $this->apiKey;
        }

        try {
            /** @var array<string, mixed> $decodedChart */
            $decodedChart = $this->coinGeckoClient->request('GET', \sprintf(self::MARKET_CHART_PATH_TEMPLATE, $coinId), [
                'headers' => $headers,
                'query' => [
                    'vs_currency' => $currency,
                    'days' => self::CHART_WINDOW_IN_DAYS,
                ],
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('CoinGecko volume series request failed', [
                'coin_id' => $coinId,
                'currency' => $currency,
                'error' => $httpError->getMessage(),
            ]);

            return [];
        }

        return $this->readVolumes($decodedChart, $coinId, $currency);
    }

    /**
     * Each point of the chart is a pair of a millisecond timestamp and a value.
     *
     * @param array<string, mixed> $decodedChart
     *
     * @return list<float>
     */
    private function readVolumes(array $decodedChart, string $coinId, string $currency): array
    {
        $volumePoints = $decodedChart['total_volumes'] ?? null;
        $volumes = \is_array($volumePoints) ? self::readVolumeOfEachPoint($volumePoints) : null;

        if (null === $volumes) {
            $this->logger->warning('CoinGecko served no usable volume series', [
                'coin_id' => $coinId,
                'currency' => $currency,
            ]);

            return [];
        }

        return $volumes;
    }

    /**
     * @param array<array-key, mixed> $volumePoints
     *
     * @return list<float>|null null as soon as one point is unreadable, a partial series being
     *                          worse than none: it would draw bars over the wrong hours
     */
    private static function readVolumeOfEachPoint(array $volumePoints): ?array
    {
        $volumes = [];
        foreach ($volumePoints as $volumePoint) {
            $volume = \is_array($volumePoint) ? $volumePoint[1] ?? null : null;
            if (!is_numeric($volume)) {
                return null;
            }

            $volumes[] = (float) $volume;
        }

        return $volumes;
    }
}
