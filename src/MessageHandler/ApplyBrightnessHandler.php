<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\PixelcastClientInterface;
use App\Client\Settings\BrightnessLevel;
use App\Config\SyncsConfigLoader;
use App\Message\ApplyBrightnessMessage;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Holds the panel at the level of the window covering the current minute. The device knows no
 * schedule of levels, so this runs for as long as the client does and stops with it.
 */
#[AsMessageHandler]
final class ApplyBrightnessHandler
{
    private ?BrightnessLevel $lastPushedLevel = null;

    public function __construct(
        private readonly SyncsConfigLoader $configLoader,
        private readonly PixelcastClientInterface $pixelcastClient,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyBrightnessMessage $message): void
    {
        try {
            // Read on every tick rather than carried by the message: a window edited by hand then
            // takes effect on the next minute, without restarting the consumer.
            $brightnessSchedule = $this->configLoader->load()->device?->brightnessSchedule;

            if (null === $brightnessSchedule) {
                return;
            }

            $levelOfTheCurrentWindow = $brightnessSchedule->levelAt($this->clock->now());

            if ($levelOfTheCurrentWindow->level === $this->lastPushedLevel?->level) {
                return;
            }

            $this->pixelcastClient->pushBrightness($levelOfTheCurrentWindow);
            $this->lastPushedLevel = $levelOfTheCurrentWindow;

            $this->logger->info('Brightness applied to the device', ['level' => $levelOfTheCurrentWindow->level]);
        } catch (\Throwable $brightnessFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next tick retry.
            $this->logger->error('Brightness could not be applied', ['exception' => $brightnessFailure]);
        }
    }
}
