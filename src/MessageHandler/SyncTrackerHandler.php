<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\PixelcastClientInterface;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\Provider\Tracker\TrackerProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncTrackerHandler
{
    /**
     * @param iterable<TrackerProviderInterface> $trackerProviders
     */
    public function __construct(
        #[AutowireIterator('app.tracker_provider')]
        private iterable $trackerProviders,
        private PixelcastClientInterface $pixelcastClient,
        private LoggerInterface $logger,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
    ) {
    }

    public function __invoke(SyncTrackerMessage $message): SyncOutcome
    {
        $trackerProvider = $this->providerServing($message->syncType);
        if (null === $trackerProvider) {
            $this->logger->error(
                'Tracker sync failed, no provider serves this sync type',
                ['sync_type' => $message->syncType],
            );

            return SyncOutcome::Failed;
        }

        try {
            $trackerPayloads = $trackerProvider->fetchTrackers();
        } catch (\Throwable $syncFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next cycle retry.
            $this->logger->error('Tracker sync failed', [
                'sync_type' => $message->syncType,
                'exception' => $syncFailure,
            ]);

            return SyncOutcome::Failed;
        }

        if ([] === $trackerPayloads) {
            $this->logger->warning(
                'Tracker sync skipped, the provider returned no tracker',
                ['sync_type' => $message->syncType],
            );

            return SyncOutcome::Skipped;
        }

        $someTrackerWasRejected = false;
        foreach ($trackerPayloads as $trackerPayload) {
            try {
                $this->pixelcastClient->pushTracker($trackerPayload);
            } catch (\Throwable $pushFailure) {
                // One rejected tracker must not hold back the rest of the group.
                $someTrackerWasRejected = true;
                $this->logger->error('Tracker push failed', [
                    'sync_type' => $message->syncType,
                    'tracker_name' => $trackerPayload->name,
                    'exception' => $pushFailure,
                ]);
            }
        }

        if ($someTrackerWasRejected) {
            return SyncOutcome::Failed;
        }

        $this->lastSuccessfulSyncStore->recordSuccess($message->syncType);

        $this->logger->info('Trackers pushed to the device', [
            'sync_type' => $message->syncType,
            'tracker_count' => \count($trackerPayloads),
        ]);

        return SyncOutcome::Pushed;
    }

    private function providerServing(string $syncType): ?TrackerProviderInterface
    {
        foreach ($this->trackerProviders as $trackerProvider) {
            if ($trackerProvider->syncType() === $syncType) {
                return $trackerProvider;
            }
        }

        return null;
    }
}
