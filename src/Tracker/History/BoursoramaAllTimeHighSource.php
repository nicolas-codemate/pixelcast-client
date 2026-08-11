<?php

declare(strict_types=1);

namespace App\Tracker\History;

use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\TrackerItem;
use App\Tracker\AllTimeHigh;
use App\Tracker\Boursorama\BoursoramaEndOfDayRequest;
use App\Tracker\Boursorama\BoursoramaQuoteSeries;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

final readonly class BoursoramaAllTimeHighSource implements AllTimeHighSourceInterface
{
    // Twenty years of daily bars, and the deepest the endpoint serves: asked for more it silently
    // answers with the intraday ticks of the current day, all dated to the Unix epoch.
    private const int REQUESTED_BAR_COUNT = 7_300;
    // The scoped client is tuned for the thirty bars of a sync cycle; twenty years weigh a few
    // hundred kilobytes, so this one request gets its own room without widening the shared bounds.
    private const int DEEP_HISTORY_TIMEOUT_IN_SECONDS = 30;
    private const int DEEP_HISTORY_MAX_DURATION_IN_SECONDS = 60;

    public function __construct(
        private BoursoramaEndOfDayRequest $endOfDayRequest,
        private LoggerInterface $logger,
    ) {
    }

    public function syncType(): string
    {
        return BoursoramaSyncConfig::syncType();
    }

    public function fetchAllTimeHigh(TrackerItem $item, \DateTimeImmutable $observedAt): ?AllTimeHigh
    {
        $quoteBars = $this->requestDeepHistory($item->symbol);
        if (null === $quoteBars) {
            return null;
        }

        $allTimeHigh = BoursoramaQuoteSeries::readAllTimeHigh(
            $quoteBars,
            $this->syncType(),
            $item->symbol,
            $item->currency,
            $observedAt,
        );

        if (null === $allTimeHigh) {
            $this->logger->warning('Boursorama served no usable deep history', ['symbol' => $item->symbol]);

            return null;
        }

        $firstBarKey = array_key_first($quoteBars);
        $firstBar = null === $firstBarKey ? null : $quoteBars[$firstBarKey];
        $this->logger->info('Boursorama deep history read', [
            'symbol' => $item->symbol,
            'bar_count' => \count($quoteBars),
            'first_bar_day' => \is_array($firstBar) ? BoursoramaQuoteSeries::readDayOf($firstBar)?->format('Y-m-d') : null,
        ]);

        return $allTimeHigh;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function requestDeepHistory(string $boursoramaCode): ?array
    {
        try {
            return $this->endOfDayRequest->fetchBars($boursoramaCode, self::REQUESTED_BAR_COUNT, [
                'timeout' => self::DEEP_HISTORY_TIMEOUT_IN_SECONDS,
                'max_duration' => self::DEEP_HISTORY_MAX_DURATION_IN_SECONDS,
            ]);
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Boursorama deep history request failed', [
                'symbol' => $boursoramaCode,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }
}
