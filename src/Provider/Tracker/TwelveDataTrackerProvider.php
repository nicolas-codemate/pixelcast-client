<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Color;
use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\TrackerItem;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Config\SyncsConfigLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TwelveDataTrackerProvider implements TrackerProviderInterface
{
    private const string TIME_SERIES_PATH = 'time_series';
    private const string API_KEY_HEADER = 'Authorization';
    private const string ERROR_STATUS = 'error';
    private const string POSITIVE_TREND_COLOR_HEX = '#00FF00';
    private const string NEGATIVE_TREND_COLOR_HEX = '#FF0000';
    private const string TICKER_EXCHANGE_SEPARATORS = ':.';
    private const int DAILY_BAR_COUNT = 30;
    private const string SPARKLINE_PERIOD = self::DAILY_BAR_COUNT.'d';

    public function __construct(
        #[Target('twelvedata.client')]
        private HttpClientInterface $twelveDataClient,
        private SyncsConfigLoader $configLoader,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
    ) {
    }

    public function syncType(): string
    {
        return TwelveDataSyncConfig::syncType();
    }

    public function fetchTrackers(): array
    {
        if (null === $this->apiKey || '' === $this->apiKey) {
            $this->logger->warning('Twelve Data needs an API key', [
                'environment_variable' => 'PIXELCAST_TWELVEDATA_API_KEY',
            ]);

            return [];
        }

        $items = $this->configLoader->load()->syncGroupOfType(TwelveDataSyncConfig::class)->items;

        $decodedResponse = $this->requestTimeSeries($items);
        if (null === $decodedResponse) {
            return [];
        }

        if (self::ERROR_STATUS === ($decodedResponse['status'] ?? null)) {
            $this->logger->warning('Twelve Data rejected the request', [
                'code' => $decodedResponse['code'] ?? null,
                'message' => self::readString($decodedResponse, 'message'),
            ]);

            return [];
        }

        $seriesBySymbol = self::seriesBySymbol($decodedResponse, $items);

        $trackerPayloads = [];
        foreach ($items as $item) {
            $series = $seriesBySymbol[$item->symbol] ?? null;
            if (null === $series) {
                $this->logger->warning('Twelve Data response is missing a requested symbol', [
                    'symbol' => $item->symbol,
                ]);

                continue;
            }

            if (self::ERROR_STATUS === ($series['status'] ?? null)) {
                $this->logger->warning('Twelve Data could not serve a requested symbol', [
                    'symbol' => $item->symbol,
                    'message' => self::readString($series, 'message'),
                ]);

                continue;
            }

            $trackerPayload = $this->buildTrackerPayload($item, $series);
            if (null !== $trackerPayload) {
                $trackerPayloads[] = $trackerPayload;
            }
        }

        return $trackerPayloads;
    }

    /**
     * @param list<TrackerItem> $items
     *
     * @return array<array-key, mixed>|null
     */
    private function requestTimeSeries(array $items): ?array
    {
        $requestedSymbols = implode(',', array_map(static fn (TrackerItem $item): string => $item->symbol, $items));

        try {
            return $this->twelveDataClient->request('GET', self::TIME_SERIES_PATH, [
                'headers' => [self::API_KEY_HEADER => 'apikey '.$this->apiKey],
                'query' => [
                    'symbol' => $requestedSymbols,
                    'interval' => '1day',
                    'outputsize' => self::DAILY_BAR_COUNT,
                    'order' => 'asc',
                ],
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Twelve Data request failed', [
                'symbols' => $requestedSymbols,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     * @param list<TrackerItem> $items
     *
     * @return array<string, array<array-key, mixed>>
     */
    private static function seriesBySymbol(array $decodedResponse, array $items): array
    {
        // Twelve Data leaves out the symbol key and returns the series alone when a single symbol is requested.
        if (isset($decodedResponse['meta']) || isset($decodedResponse['values'])) {
            return 1 === \count($items) ? [$items[0]->symbol => $decodedResponse] : [];
        }

        $seriesBySymbol = [];
        foreach ($decodedResponse as $symbol => $series) {
            if (\is_string($symbol) && \is_array($series)) {
                $seriesBySymbol[$symbol] = $series;
            }
        }

        return $seriesBySymbol;
    }

    /**
     * @param array<array-key, mixed> $series
     */
    private function buildTrackerPayload(TrackerItem $item, array $series): ?TrackerPayload
    {
        $closingPrices = self::readClosingPrices($series);
        $closingPriceCount = \count($closingPrices);

        if ($closingPriceCount < 2 || 0.0 === $closingPrices[$closingPriceCount - 2]) {
            $this->logger->warning('Unexpected Twelve Data series shape', ['symbol' => $item->symbol]);

            return null;
        }

        $latestClosingPrice = $closingPrices[$closingPriceCount - 1];
        $previousClosingPrice = $closingPrices[$closingPriceCount - 2];

        $changePercentage = round(($latestClosingPrice - $previousClosingPrice) / $previousClosingPrice * 100, 2);
        $trendColor = Color::fromHexCode($changePercentage >= 0 ? self::POSITIVE_TREND_COLOR_HEX : self::NEGATIVE_TREND_COLOR_HEX);
        $sparklinePoints = SparklineDownsampler::downsampleToAtMost($closingPrices, TrackerPayload::MAXIMUM_SPARKLINE_POINTS);
        $quotedCurrency = self::readQuotedCurrency($series) ?? $item->currency;

        try {
            return new TrackerPayload(
                name: mb_strtoupper($item->symbol),
                symbol: $item->label ?? self::displayTickerOf($item->symbol),
                iconName: $item->icon,
                currency: mb_strtoupper($quotedCurrency),
                currentValue: $latestClosingPrice,
                changePercentage: $changePercentage,
                sparklinePoints: $sparklinePoints,
                symbolColor: $item->labelColor ?? $trendColor,
                sparklineColor: $trendColor,
                sparklinePeriod: self::SPARKLINE_PERIOD,
                bottomText: $item->bottomText,
            );
        } catch (\InvalidArgumentException $validationError) {
            $this->logger->warning('Twelve Data series could not be turned into a tracker', [
                'symbol' => $item->symbol,
                'error' => $validationError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $series
     *
     * @return list<float>
     */
    private static function readClosingPrices(array $series): array
    {
        $bars = $series['values'] ?? null;
        if (!\is_array($bars)) {
            return [];
        }

        $closingPrices = [];
        foreach ($bars as $bar) {
            if (!\is_array($bar)) {
                return [];
            }

            $closingPrice = self::readNumber($bar, 'close');
            if (null === $closingPrice) {
                return [];
            }

            $closingPrices[] = $closingPrice;
        }

        return $closingPrices;
    }

    /**
     * @param array<array-key, mixed> $series
     */
    private static function readQuotedCurrency(array $series): ?string
    {
        $meta = $series['meta'] ?? null;

        return \is_array($meta) ? self::readString($meta, 'currency') : null;
    }

    private static function displayTickerOf(string $configuredSymbol): string
    {
        $tickerWithoutExchange = strtok($configuredSymbol, self::TICKER_EXCHANGE_SEPARATORS);

        return mb_strtoupper(false === $tickerWithoutExchange ? $configuredSymbol : $tickerWithoutExchange);
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
}
