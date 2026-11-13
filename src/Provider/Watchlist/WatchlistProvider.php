<?php

declare(strict_types=1);

namespace App\Provider\Watchlist;

use App\Client\Watchlist\WatchlistPayload;
use App\Client\Watchlist\WatchlistRow;
use App\Config\Sync\WatchlistItem;
use App\Config\Sync\WatchlistQuoteSource;
use App\Config\Sync\WatchlistSyncConfig;
use App\Config\SyncsConfigLoader;
use App\Provider\Tracker\BoursoramaTrackerProvider;
use Psr\Log\LoggerInterface;

final readonly class WatchlistProvider implements WatchlistProviderInterface
{
    private const string WATCHLIST_NAME = 'watchlist';

    public function __construct(
        private SyncsConfigLoader $configLoader,
        private BoursoramaWatchlistQuoteReader $boursoramaQuoteReader,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchWatchlist(): ?WatchlistPayload
    {
        $watchlistSyncGroup = $this->configLoader->load()->syncGroupOfType(WatchlistSyncConfig::class);

        $rows = [];
        foreach ($watchlistSyncGroup->items as $item) {
            $quote = $this->readQuote($item);
            if (null === $quote) {
                $this->logger->warning('Watchlist quote could not be read, the device keeps the list it shows', [
                    'source' => $item->source->value,
                    'symbol' => $item->symbol,
                ]);

                return null;
            }

            $rows[] = WatchlistRow::create(
                symbol: $item->label ?? self::displaySymbolOf($item),
                value: $quote->price,
                changePercentage: $quote->changePercentage,
                currency: mb_strtoupper($item->currency),
                iconName: $item->icon,
                symbolColor: $item->labelColor,
            );
        }

        return WatchlistPayload::create(
            name: self::WATCHLIST_NAME,
            rows: $rows,
            staleAfterInSeconds: $watchlistSyncGroup->staleDeclaration->staleAfterInSeconds,
            staleBehavior: $watchlistSyncGroup->staleDeclaration->staleBehavior,
        );
    }

    private function readQuote(WatchlistItem $item): ?WatchlistQuote
    {
        return match ($item->source) {
            WatchlistQuoteSource::Boursorama => $this->boursoramaQuoteReader->readQuote($item->symbol),
        };
    }

    private static function displaySymbolOf(WatchlistItem $item): string
    {
        return match ($item->source) {
            WatchlistQuoteSource::Boursorama => BoursoramaTrackerProvider::displaySymbolOf($item->symbol),
        };
    }
}
