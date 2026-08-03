<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\PixelcastClientInterface;
use App\Config\Sync\WeatherSyncConfig;
use App\Health\LastSuccessfulSyncStore;
use App\Message\SyncOutcome;
use App\Message\SyncWeatherMessage;
use App\Provider\Weather\WeatherProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncWeatherHandler
{
    public function __construct(
        private WeatherProviderInterface $weatherProvider,
        private PixelcastClientInterface $pixelcastClient,
        private LoggerInterface $logger,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
    ) {
    }

    public function __invoke(SyncWeatherMessage $message): SyncOutcome
    {
        try {
            $weather = $this->weatherProvider->fetchWeather();
            if (null === $weather) {
                $this->logger->warning('Weather sync skipped, the provider returned no payload');

                return SyncOutcome::Skipped;
            }

            $this->pixelcastClient->pushWeather($weather);
            $this->lastSuccessfulSyncStore->recordSuccess(WeatherSyncConfig::syncType());

            $this->logger->info('Weather pushed to the device', ['forecast_days' => \count($weather->forecastDays)]);

            return SyncOutcome::Pushed;
        } catch (\Throwable $syncFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next cycle retry.
            $this->logger->error('Weather sync failed', ['exception' => $syncFailure]);

            return SyncOutcome::Failed;
        }
    }
}
