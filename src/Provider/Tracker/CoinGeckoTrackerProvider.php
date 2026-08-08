<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Color;
use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\TrackerItem;
use App\Config\SyncsConfigLoader;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CoinGeckoTrackerProvider implements TrackerProviderInterface
{
    private const string MARKETS_PATH = 'coins/markets';
    private const string API_KEY_HEADER = 'x-cg-demo-api-key';
    // CoinGecko serves a single series, sparkline_in_7d, whatever the requested currency.
    private const string SPARKLINE_PERIOD = '7d';
    private const string POSITIVE_TREND_COLOR_HEX = '#00FF00';
    private const string NEGATIVE_TREND_COLOR_HEX = '#FF0000';

    public function __construct(
        #[Target('coingecko.client')]
        private HttpClientInterface $coinGeckoClient,
        private SyncsConfigLoader $configLoader,
        private CoinGeckoMidnightPriceProvider $midnightPriceProvider,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
    ) {
    }

    public function syncType(): string
    {
        return CoinGeckoSyncConfig::syncType();
    }

    public function fetchTrackers(): array
    {
        $coinGeckoSyncGroup = $this->configLoader->load()->syncGroupOfType(CoinGeckoSyncConfig::class);

        $trackerPayloads = [];
        foreach (self::itemsByCurrency($coinGeckoSyncGroup->activeItemsAt($this->clock->now())) as $currency => $itemsForCurrency) {
            $rawMarkets = $this->requestMarkets(self::coinIdsOf($itemsForCurrency), $currency);
            if (null === $rawMarkets) {
                continue;
            }

            $marketsByCoinId = self::marketsByCoinId($rawMarkets);

            foreach ($itemsForCurrency as $item) {
                $market = $marketsByCoinId[$item->symbol] ?? null;
                if (null === $market) {
                    $this->logger->warning('CoinGecko response is missing a requested coin', [
                        'coin_id' => $item->symbol,
                        'currency' => $currency,
                    ]);

                    continue;
                }

                $trackerPayload = $this->buildTrackerPayload($item, $market);
                if (null !== $trackerPayload) {
                    $trackerPayloads[] = $trackerPayload;
                }
            }
        }

        return $trackerPayloads;
    }

    /**
     * @param list<TrackerItem> $items
     *
     * @return array<string, list<TrackerItem>>
     */
    private static function itemsByCurrency(array $items): array
    {
        $itemsByCurrency = [];
        foreach ($items as $item) {
            $itemsByCurrency[$item->currency][] = $item;
        }

        return $itemsByCurrency;
    }

    /**
     * @param list<TrackerItem> $items
     *
     * @return list<string>
     */
    private static function coinIdsOf(array $items): array
    {
        return array_map(static fn (TrackerItem $item): string => $item->symbol, $items);
    }

    /**
     * @param list<string> $coinIds
     *
     * @return list<array<string, mixed>>|null
     */
    private function requestMarkets(array $coinIds, string $currency): ?array
    {
        $headers = [];
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers[self::API_KEY_HEADER] = $this->apiKey;
        }

        try {
            /** @var list<array<string, mixed>> $decodedMarkets */
            $decodedMarkets = $this->coinGeckoClient->request('GET', self::MARKETS_PATH, [
                'headers' => $headers,
                'query' => [
                    'vs_currency' => $currency,
                    'ids' => implode(',', $coinIds),
                    'sparkline' => 'true',
                ],
            ])->toArray();

            return $decodedMarkets;
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('CoinGecko request failed', [
                'currency' => $currency,
                'coin_ids' => $coinIds,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $rawMarkets
     *
     * @return array<string, array<string, mixed>>
     */
    private static function marketsByCoinId(array $rawMarkets): array
    {
        $marketsByCoinId = [];
        foreach ($rawMarkets as $market) {
            $coinId = self::readString($market, 'id');
            if (null !== $coinId) {
                $marketsByCoinId[$coinId] = $market;
            }
        }

        return $marketsByCoinId;
    }

    /**
     * @param array<string, mixed> $market
     */
    private function buildTrackerPayload(TrackerItem $item, array $market): ?TrackerPayload
    {
        $tickerSymbol = self::readString($market, 'symbol');
        $currentPrice = self::readNumber($market, 'current_price');

        if (null === $tickerSymbol || null === $currentPrice) {
            $this->logger->warning('Unexpected CoinGecko market shape', ['coin_id' => $item->symbol]);

            return null;
        }

        $midnightPrice = $this->midnightPriceProvider->priceAtMidnightOf($item->symbol, $item->currency);
        if (null === $midnightPrice || 0.0 === $midnightPrice) {
            $this->logger->warning('CoinGecko tracker skipped for lack of a midnight price', ['coin_id' => $item->symbol]);

            return null;
        }

        $changePercentage = round(($currentPrice - $midnightPrice) / $midnightPrice * 100, 2);

        $tickerSymbolUppercase = mb_strtoupper($tickerSymbol);
        $trendColor = Color::fromHexCode($changePercentage >= 0 ? self::POSITIVE_TREND_COLOR_HEX : self::NEGATIVE_TREND_COLOR_HEX);
        $sparklinePoints = SparklineDownsampler::downsampleToAtMost(self::readSparklinePoints($market), TrackerPayload::MAXIMUM_SPARKLINE_POINTS);
        $tradedVolumeText = TradedVolumeBottomText::composeFrom(self::readNumber($market, 'total_volume'), $item->currency);

        try {
            return new TrackerPayload(
                name: $tickerSymbolUppercase,
                symbol: $item->label ?? $tickerSymbolUppercase,
                iconName: $item->icon,
                currency: mb_strtoupper($item->currency),
                currentValue: $currentPrice,
                changePercentage: $changePercentage,
                sparklinePoints: $sparklinePoints,
                symbolColor: $item->labelColor ?? $trendColor,
                sparklineColor: $trendColor,
                sparklinePeriod: self::SPARKLINE_PERIOD,
                bottomText: $item->bottomText ?? $tradedVolumeText,
                staleAfterInSeconds: $item->staleDeclaration->staleAfterInSeconds,
                staleBehavior: $item->staleDeclaration->staleBehavior,
            );
        } catch (\InvalidArgumentException $validationError) {
            $this->logger->warning('CoinGecko market could not be turned into a tracker', [
                'coin_id' => $item->symbol,
                'error' => $validationError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $market
     *
     * @return list<float>
     */
    private static function readSparklinePoints(array $market): array
    {
        $sparklineBlock = $market['sparkline_in_7d'] ?? null;
        if (!\is_array($sparklineBlock)) {
            return [];
        }

        return self::readNumberSeries($sparklineBlock, 'price') ?? [];
    }

    /**
     * @param array<array-key, mixed> $block
     */
    private static function readNumber(array $block, string $key): ?float
    {
        $value = $block[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<array-key, mixed> $block
     */
    private static function readString(array $block, string $key): ?string
    {
        $value = $block[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $block
     *
     * @return list<float>|null
     */
    private static function readNumberSeries(array $block, string $key): ?array
    {
        $series = $block[$key] ?? null;
        if (!\is_array($series)) {
            return null;
        }

        $numbers = [];
        foreach ($series as $value) {
            if (!is_numeric($value)) {
                return null;
            }

            $numbers[] = (float) $value;
        }

        return $numbers;
    }
}
