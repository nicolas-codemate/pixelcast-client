<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Color;
use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\TrackerItem;
use App\Config\SyncsConfigLoader;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class BoursoramaTrackerProvider implements TrackerProviderInterface
{
    private const string TICKS_PATH = 'bourse/action/graph/ws/GetTicksEOD';
    private const int REQUESTED_BAR_COUNT = 30;
    private const int END_OF_DAY_PERIOD = 0;
    private const string POSITIVE_TREND_COLOR_HEX = '#00FF00';
    private const string NEGATIVE_TREND_COLOR_HEX = '#FF0000';
    private const string BOURSORAMA_CODE_PREFIX_PATTERN = '/^\d[a-z][A-Z]/';
    private const string BROWSER_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    private const string AJAX_REQUESTED_WITH = 'XMLHttpRequest';

    public function __construct(
        #[Target('boursorama.client')]
        private HttpClientInterface $boursoramaClient,
        private SyncsConfigLoader $configLoader,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function syncType(): string
    {
        return BoursoramaSyncConfig::syncType();
    }

    public function fetchTrackers(): array
    {
        $boursoramaSyncGroup = $this->configLoader->load()->syncGroupOfType(BoursoramaSyncConfig::class);

        $trackerPayloads = [];
        foreach ($boursoramaSyncGroup->activeItemsAt($this->clock->now()) as $item) {
            $decodedResponse = $this->requestQuoteTab($item->symbol);
            if (null === $decodedResponse) {
                continue;
            }

            $trackerPayload = $this->buildTrackerPayload($item, $decodedResponse);
            if (null !== $trackerPayload) {
                $trackerPayloads[] = $trackerPayload;
            }
        }

        return $trackerPayloads;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function requestQuoteTab(string $boursoramaCode): ?array
    {
        try {
            return $this->boursoramaClient->request('GET', self::TICKS_PATH, [
                // Without both of these the endpoint answers 200 with an empty quote
                // list instead of an error, so a missing header reads as an unknown code.
                'headers' => [
                    'User-Agent' => self::BROWSER_USER_AGENT,
                    'X-Requested-With' => self::AJAX_REQUESTED_WITH,
                ],
                'query' => [
                    'symbol' => $boursoramaCode,
                    'length' => self::REQUESTED_BAR_COUNT,
                    'period' => self::END_OF_DAY_PERIOD,
                    'guid' => '',
                ],
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Boursorama request failed', [
                'symbol' => $boursoramaCode,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     */
    private function buildTrackerPayload(TrackerItem $item, array $decodedResponse): ?TrackerPayload
    {
        $quoteBars = self::readQuoteBars($decodedResponse);

        if ([] === $quoteBars) {
            $this->logger->warning('Boursorama served no quotes for a symbol', ['symbol' => $item->symbol]);

            return null;
        }

        $closingPrices = self::readClosingPrices($quoteBars);
        $closingPriceCount = null === $closingPrices ? 0 : \count($closingPrices);

        if (null === $closingPrices || $closingPriceCount < 2 || 0.0 === $closingPrices[$closingPriceCount - 2]) {
            $this->logger->warning('Unexpected Boursorama quote series shape', [
                'symbol' => $item->symbol,
                'bar_count' => \count($quoteBars),
            ]);

            return null;
        }

        $latestClosingPrice = $closingPrices[$closingPriceCount - 1];
        $previousClosingPrice = $closingPrices[$closingPriceCount - 2];

        $changePercentage = round(($latestClosingPrice - $previousClosingPrice) / $previousClosingPrice * 100, 2);
        $trendColor = Color::fromHexCode($changePercentage >= 0 ? self::POSITIVE_TREND_COLOR_HEX : self::NEGATIVE_TREND_COLOR_HEX);
        $sparklinePoints = SparklineDownsampler::downsampleToAtMost($closingPrices, TrackerPayload::MAXIMUM_SPARKLINE_POINTS);

        try {
            return new TrackerPayload(
                name: mb_strtoupper($item->symbol),
                symbol: $item->label ?? self::displaySymbolOf($item->symbol),
                iconName: $item->icon,
                currency: mb_strtoupper($item->currency),
                currentValue: $latestClosingPrice,
                changePercentage: $changePercentage,
                sparklinePoints: $sparklinePoints,
                symbolColor: $item->labelColor ?? $trendColor,
                sparklineColor: $trendColor,
                sparklinePeriod: \sprintf('%dd', $closingPriceCount),
                bottomText: $item->bottomText,
                staleAfterInSeconds: $item->staleDeclaration->staleAfterInSeconds,
                staleBehavior: $item->staleDeclaration->staleBehavior,
            );
        } catch (\InvalidArgumentException $validationError) {
            $this->logger->warning('Boursorama quotes could not be turned into a tracker', [
                'symbol' => $item->symbol,
                'error' => $validationError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     *
     * @return array<array-key, mixed>
     */
    private static function readQuoteBars(array $decodedResponse): array
    {
        $quoteBlock = $decodedResponse['d'] ?? null;
        $quoteBars = \is_array($quoteBlock) ? ($quoteBlock['QuoteTab'] ?? null) : null;

        return \is_array($quoteBars) ? $quoteBars : [];
    }

    /**
     * @param array<array-key, mixed> $quoteBars
     *
     * @return list<float>|null
     */
    private static function readClosingPrices(array $quoteBars): ?array
    {
        // The last daily bar is the running session, not a settled close: its close is the live price.
        $closingPrices = [];
        foreach ($quoteBars as $quoteBar) {
            if (!\is_array($quoteBar)) {
                return null;
            }

            $closingPrice = self::readNumber($quoteBar, 'c');
            if (null === $closingPrice) {
                return null;
            }

            $closingPrices[] = $closingPrice;
        }

        return $closingPrices;
    }

    private static function displaySymbolOf(string $boursoramaCode): string
    {
        $codeWithoutPrefix = preg_replace(self::BOURSORAMA_CODE_PREFIX_PATTERN, '', $boursoramaCode);
        if (null === $codeWithoutPrefix || '' === $codeWithoutPrefix) {
            $codeWithoutPrefix = $boursoramaCode;
        }

        return mb_strtoupper($codeWithoutPrefix);
    }

    /**
     * @param array<array-key, mixed> $block
     */
    private static function readNumber(array $block, string $key): ?float
    {
        $value = $block[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
