<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\PixelcastClientInterface;
use App\Config\Sync\HomeAssistantSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncHomeAssistantMessage;
use App\Message\SyncOutcome;
use App\Provider\HomeAssistant\HomeAssistantSensorProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncHomeAssistantHandler
{
    public function __construct(
        private HomeAssistantSensorProviderInterface $homeAssistantSensorProvider,
        private PixelcastClientInterface $pixelcastClient,
        private LoggerInterface $logger,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
    ) {
    }

    public function __invoke(SyncHomeAssistantMessage $message): SyncOutcome
    {
        try {
            $customApp = $this->homeAssistantSensorProvider->fetchSensorsCustomApp();

            if (null === $customApp) {
                $this->logger->warning('Home Assistant sync skipped, the provider returned no payload');

                return SyncOutcome::Skipped;
            }

            $this->pixelcastClient->pushCustomApp($customApp);

            $this->logger->info('Home Assistant sensors pushed to the device', [
                'custom_app' => $customApp->name,
            ]);

            $this->lastSuccessfulSyncStore->recordSuccess(HomeAssistantSyncConfig::syncType());

            return SyncOutcome::Pushed;
        } catch (\Throwable $syncFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next cycle retry.
            $this->logger->error('Home Assistant sync failed', ['exception' => $syncFailure]);

            return SyncOutcome::Failed;
        }
    }
}
