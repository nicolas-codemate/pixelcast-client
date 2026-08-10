<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\PixelcastClientInterface;
use App\Config\Sync\ClaudeSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncClaudeMessage;
use App\Message\SyncOutcome;
use App\Provider\Claude\ClaudeUsageProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncClaudeHandler
{
    public function __construct(
        private ClaudeUsageProviderInterface $claudeUsageProvider,
        private PixelcastClientInterface $pixelcastClient,
        private LoggerInterface $logger,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
    ) {
    }

    public function __invoke(SyncClaudeMessage $message): SyncOutcome
    {
        try {
            $usageGauge = $this->claudeUsageProvider->fetchUsageGauge();
            if (null === $usageGauge) {
                $this->logger->warning('Claude sync skipped, the provider returned no payload');

                return SyncOutcome::Skipped;
            }

            $this->pixelcastClient->pushGauge($usageGauge);
            $this->lastSuccessfulSyncStore->recordSuccess(ClaudeSyncConfig::syncType());

            $this->logger->info('Claude usage pushed to the device', [
                'rows' => \count($usageGauge->rows),
            ]);

            return SyncOutcome::Pushed;
        } catch (\Throwable $syncFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next cycle retry.
            $this->logger->error('Claude sync failed', ['exception' => $syncFailure]);

            return SyncOutcome::Failed;
        }
    }
}
