<?php

declare(strict_types=1);

namespace App\Tracker\History;

use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\TrackerItem;
use App\Tracker\AllTimeHigh;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class BoursoramaAllTimeHighSource implements AllTimeHighSourceInterface
{
    private const string TICKS_PATH = 'bourse/action/graph/ws/GetTicksEOD';
    // Twenty years of daily bars, and the deepest the endpoint serves: asked for more it silently
    // answers with the intraday ticks of the current day, all dated to the Unix epoch.
    private const int REQUESTED_BAR_COUNT = 7_300;
    private const int END_OF_DAY_PERIOD = 0;
    private const string BROWSER_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    private const string AJAX_REQUESTED_WITH = 'XMLHttpRequest';
    // The scoped client is tuned for the thirty bars of a sync cycle; twenty years weigh a few
    // hundred kilobytes, so this one request gets its own room without widening the shared bounds.
    private const int DEEP_HISTORY_TIMEOUT_IN_SECONDS = 30;
    private const int DEEP_HISTORY_MAX_DURATION_IN_SECONDS = 60;

    public function __construct(
        #[Target('boursorama.client')]
        private HttpClientInterface $boursoramaClient,
        private LoggerInterface $logger,
    ) {
    }

    public function syncType(): string
    {
        return BoursoramaSyncConfig::syncType();
    }

    public function fetchAllTimeHigh(TrackerItem $item, \DateTimeImmutable $observedAt): ?AllTimeHigh
    {
        $decodedResponse = $this->requestDeepHistory($item->symbol);
        if (null === $decodedResponse) {
            return null;
        }

        $quoteBars = BoursoramaQuoteSeries::readBars($decodedResponse);
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

        $firstBar = reset($quoteBars);
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
                'timeout' => self::DEEP_HISTORY_TIMEOUT_IN_SECONDS,
                'max_duration' => self::DEEP_HISTORY_MAX_DURATION_IN_SECONDS,
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Boursorama deep history request failed', [
                'symbol' => $boursoramaCode,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }
}
