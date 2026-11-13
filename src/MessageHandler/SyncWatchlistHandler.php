<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\PixelcastClientInterface;
use App\Config\Sync\WatchlistSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncWatchlistMessage;
use App\Provider\Watchlist\WatchlistProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncWatchlistHandler
{
    public function __construct(
        private WatchlistProviderInterface $watchlistProvider,
        private PixelcastClientInterface $pixelcastClient,
        private LoggerInterface $logger,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
    ) {
    }

    public function __invoke(SyncWatchlistMessage $message): SyncOutcome
    {
        try {
            $watchlist = $this->watchlistProvider->fetchWatchlist();

            if (null === $watchlist) {
                // A quote the provider could not read is a failure to report, not a quiet cycle
                $this->logger->error('Watchlist sync failed, a quote could not be read');

                return SyncOutcome::Failed;
            }

            $this->pixelcastClient->pushWatchlist($watchlist);

            $this->logger->info('Watchlist pushed to the device', [
                'watchlist' => $watchlist->name,
                'row_count' => \count($watchlist->rows),
            ]);

            $this->lastSuccessfulSyncStore->recordSuccess(WatchlistSyncConfig::syncType());

            return SyncOutcome::Pushed;
        } catch (\Throwable $syncFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next cycle retry.
            $this->logger->error('Watchlist sync failed', ['exception' => $syncFailure]);

            return SyncOutcome::Failed;
        }
    }
}
