<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Color;
use App\Client\Tracker\TrackerPayload;
use App\Config\Sync\BottomLine;
use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\TrackerItem;
use App\Config\SyncsConfigLoader;
use App\Tracker\AllTimeHigh;
use App\Tracker\AllTimeHighStore;
use App\Tracker\Boursorama\BoursoramaEndOfDayRequest;
use App\Tracker\Boursorama\BoursoramaQuoteSeries;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

final readonly class BoursoramaTrackerProvider implements TrackerProviderInterface
{
    private const int REQUESTED_BAR_COUNT = 30;
    private const string POSITIVE_TREND_COLOR_HEX = '#00FF00';
    private const string NEGATIVE_TREND_COLOR_HEX = '#FF0000';
    private const string BOURSORAMA_CODE_PREFIX_PATTERN = '/^\d[a-z][A-Z]/';

    public function __construct(
        private BoursoramaEndOfDayRequest $endOfDayRequest,
        private SyncsConfigLoader $configLoader,
        private AllTimeHighStore $allTimeHighStore,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function syncType(): string
    {
        return BoursoramaSyncConfig::syncType();
    }

    public function fetchTrackers(?\DateTimeImmutable $activeWindowInstant): array
    {
        $boursoramaSyncGroup = $this->configLoader->load()->syncGroupOfType(BoursoramaSyncConfig::class);

        $trackerPayloads = [];
        foreach ($boursoramaSyncGroup->itemsToFetchAt($activeWindowInstant) as $item) {
            $quoteBars = $this->requestQuoteBars($item->symbol);
            if (null === $quoteBars) {
                continue;
            }

            $trackerPayload = $this->buildTrackerPayload($item, $quoteBars);
            if (null !== $trackerPayload) {
                $trackerPayloads[] = $trackerPayload;
            }
        }

        return $trackerPayloads;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function requestQuoteBars(string $boursoramaCode): ?array
    {
        try {
            return $this->endOfDayRequest->fetchBars($boursoramaCode, self::REQUESTED_BAR_COUNT);
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Boursorama request failed', [
                'symbol' => $boursoramaCode,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $quoteBars
     */
    private function buildTrackerPayload(TrackerItem $item, array $quoteBars): ?TrackerPayload
    {
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
        $volumePoints = $item->volumeBars ? TradedVolumeSeries::alignedWith($closingPrices, self::readTradedVolumes($quoteBars)) : [];

        $allTimeHigh = $this->raiseStoredAllTimeHigh($item, $quoteBars);
        $bottomText = $item->bottomText ?? match ($item->bottomLine) {
            BottomLine::AllTimeHigh => AllTimeHighBottomText::composeFrom($allTimeHigh),
            BottomLine::TradedVolume, null => null,
        };

        try {
            return new TrackerPayload(
                name: mb_strtoupper($item->symbol),
                symbol: $item->label ?? self::displaySymbolOf($item->symbol),
                iconName: $item->icon,
                currency: mb_strtoupper($item->currency),
                currentValue: $latestClosingPrice,
                changePercentage: $changePercentage,
                sparklinePoints: $sparklinePoints,
                volumePoints: $volumePoints,
                symbolColor: $item->labelColor ?? $trendColor,
                sparklineColor: $trendColor,
                sparklinePeriod: SparklinePeriodLabel::forDayCount(self::readCoveredDayCount($quoteBars)),
                bottomText: $bottomText,
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
     * @param array<array-key, mixed> $quoteBars
     */
    private function raiseStoredAllTimeHigh(TrackerItem $item, array $quoteBars): ?AllTimeHigh
    {
        $observedAllTimeHigh = BoursoramaQuoteSeries::readAllTimeHigh(
            $quoteBars,
            $this->syncType(),
            $item->symbol,
            $item->currency,
            $this->clock->now(),
        );

        if (null === $observedAllTimeHigh) {
            return null;
        }

        return $this->allTimeHighStore->raiseTo($observedAllTimeHigh);
    }

    /**
     * Each bar carries its day as a count of days since the Unix epoch, so the calendar the
     * series spans is the distance between the first bar and the last.
     *
     * @param array<array-key, mixed> $quoteBars
     */
    private static function readCoveredDayCount(array $quoteBars): ?int
    {
        $firstBar = reset($quoteBars);
        $lastBar = end($quoteBars);

        $firstDay = \is_array($firstBar) ? self::readNumber($firstBar, 'd') : null;
        $lastDay = \is_array($lastBar) ? self::readNumber($lastBar, 'd') : null;

        if (null === $firstDay || null === $lastDay) {
            return null;
        }

        return (int) ($lastDay - $firstDay);
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

    /**
     * @param array<array-key, mixed> $quoteBars
     *
     * @return list<float> empty as soon as one bar carries no volume, since a partial series
     *                     would not line up with the price curve
     */
    private static function readTradedVolumes(array $quoteBars): array
    {
        $tradedVolumes = [];
        foreach ($quoteBars as $quoteBar) {
            $tradedVolume = \is_array($quoteBar) ? self::readNumber($quoteBar, 'v') : null;
            if (null === $tradedVolume) {
                return [];
            }

            $tradedVolumes[] = $tradedVolume;
        }

        return $tradedVolumes;
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
