<?php

declare(strict_types=1);

namespace App\Provider\Watchlist;

use App\Tracker\Boursorama\BoursoramaEndOfDayRequest;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * The running price of a Boursorama code and its change since the close of the eve, read the way the
 * Boursorama tracker reads them: from the session closes first, from the last two daily bars when
 * the endpoint serves no session.
 */
final readonly class BoursoramaWatchlistQuoteReader
{
    // A weekend or a bank holiday sits between two sessions, so two calendar bars are not enough
    private const int FALLBACK_BAR_COUNT = 5;
    private const int CHANGE_DECIMALS = 2;

    public function __construct(
        private BoursoramaEndOfDayRequest $endOfDayRequest,
        private LoggerInterface $logger,
    ) {
    }

    public function readQuote(string $boursoramaCode): ?WatchlistQuote
    {
        try {
            $sessionCloses = $this->endOfDayRequest->fetchSessionCloses($boursoramaCode);
            if (null !== $sessionCloses) {
                return self::quoteFrom($sessionCloses->runningSessionPrice, $sessionCloses->previousSessionClose);
            }

            return $this->quoteFromTheLastBars($boursoramaCode);
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('Boursorama request failed', [
                'symbol' => $boursoramaCode,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @throws HttpClientExceptionInterface
     */
    private function quoteFromTheLastBars(string $boursoramaCode): ?WatchlistQuote
    {
        $closingPrices = [];
        foreach ($this->endOfDayRequest->fetchBars($boursoramaCode, self::FALLBACK_BAR_COUNT) as $quoteBar) {
            $closingPrice = \is_array($quoteBar) ? ($quoteBar['c'] ?? null) : null;
            if (is_numeric($closingPrice)) {
                $closingPrices[] = (float) $closingPrice;
            }
        }

        $closingPriceCount = \count($closingPrices);
        if ($closingPriceCount < 2) {
            $this->logger->warning('Boursorama served too few quotes for a symbol', [
                'symbol' => $boursoramaCode,
                'closing_price_count' => $closingPriceCount,
            ]);

            return null;
        }

        return self::quoteFrom($closingPrices[$closingPriceCount - 1], $closingPrices[$closingPriceCount - 2]);
    }

    private static function quoteFrom(float $latestPrice, float $previousClose): ?WatchlistQuote
    {
        if (0.0 === $previousClose) {
            return null;
        }

        return new WatchlistQuote(
            price: $latestPrice,
            changePercentage: round(($latestPrice - $previousClose) / $previousClose * 100, self::CHANGE_DECIMALS),
        );
    }
}
