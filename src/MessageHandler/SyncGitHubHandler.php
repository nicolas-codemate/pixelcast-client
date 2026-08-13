<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\Exception\ResourceNotFoundException;
use App\Client\PixelcastClientInterface;
use App\Config\Sync\GitHubSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncGitHubMessage;
use App\Message\SyncOutcome;
use App\Provider\GitHub\GitHubPullRequestProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncGitHubHandler
{
    public function __construct(
        private GitHubPullRequestProviderInterface $gitHubPullRequestProvider,
        private PixelcastClientInterface $pixelcastClient,
        private LoggerInterface $logger,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
    ) {
    }

    public function __invoke(SyncGitHubMessage $message): SyncOutcome
    {
        try {
            $countDisplay = $this->gitHubPullRequestProvider->fetchPullRequestCountDisplay();

            $customAppToPush = $countDisplay->customAppToPush;
            if (null !== $customAppToPush) {
                $this->pixelcastClient->pushCustomApp($customAppToPush);
                $this->lastSuccessfulSyncStore->recordSuccess(GitHubSyncConfig::syncType());

                $this->logger->info('GitHub pull request count pushed to the device', [
                    'custom_app' => $customAppToPush->name,
                ]);

                return SyncOutcome::Pushed;
            }

            $customAppNameToDelete = $countDisplay->customAppNameToDelete;
            if (null !== $customAppNameToDelete) {
                $this->removeCustomApp($customAppNameToDelete);
                // A screen left empty on purpose is a cycle that did its job, and a long quiet week
                // must not read as a group that stopped running.
                $this->lastSuccessfulSyncStore->recordSuccess(GitHubSyncConfig::syncType());

                $this->logger->info('No pull request waits for a review, the GitHub app left the device', [
                    'custom_app' => $customAppNameToDelete,
                ]);

                return SyncOutcome::Pushed;
            }

            $this->logger->warning('GitHub sync skipped, the provider returned no payload');

            return SyncOutcome::Skipped;
        } catch (\Throwable $syncFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next cycle retry.
            $this->logger->error('GitHub sync failed', ['exception' => $syncFailure]);

            return SyncOutcome::Failed;
        }
    }

    private function removeCustomApp(string $customAppName): void
    {
        try {
            $this->pixelcastClient->deleteCustomApp($customAppName);
        } catch (ResourceNotFoundException) {
            // The first quiet cycle finds no app to remove, which is already the state wanted.
        }
    }
}
