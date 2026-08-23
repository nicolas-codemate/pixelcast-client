<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Client\PixelcastClientInterface;
use App\Config\SyncsConfigLoader;
use App\Device\LastPushedBrightnessStore;
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
    private bool $aLevelWasAlreadyPushedInThisProcess = false;

    public function __construct(
        private readonly SyncsConfigLoader $configLoader,
        private readonly PixelcastClientInterface $pixelcastClient,
        private readonly LastPushedBrightnessStore $lastPushedBrightnessStore,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyBrightnessMessage $message): void
    {
        try {
            // Read on every run rather than carried by the message: editing the level of a declared
            // window takes effect on the next run. Editing its hours, or gaining a first window,
            // waits for the consumer to be recycled, since the scheduler reads the windows it wakes
            // up on only at startup.
            $brightnessSchedule = $this->configLoader->load()->brightnessSchedule();

            if (null === $brightnessSchedule) {
                return;
            }

            $levelOfTheCurrentWindow = $brightnessSchedule->levelAt($this->clock->now());

            if ($this->theCallCanBeSkipped($levelOfTheCurrentWindow->level)) {
                return;
            }

            $this->pixelcastClient->pushBrightness($levelOfTheCurrentWindow);
            $this->lastPushedBrightnessStore->recordPushedLevel($levelOfTheCurrentWindow);
            $this->aLevelWasAlreadyPushedInThisProcess = true;

            $this->logger->info('Brightness applied to the device', ['level' => $levelOfTheCurrentWindow->level]);
        } catch (\Throwable $brightnessFailure) {
            // Never rethrow: the scheduler consumer must keep running and let the next tick retry.
            $this->logger->error('Brightness could not be applied', ['exception' => $brightnessFailure]);
        }
    }

    /**
     * The store is shared with every other writer of the brightness, app:device:settings first of
     * all, so a level pushed outside the schedule is seen here rather than silently held.
     *
     * The first tick of a process pushes whatever the store holds: nothing guarantees the panel
     * was left alone while the client was down.
     */
    private function theCallCanBeSkipped(int $levelOfTheCurrentWindow): bool
    {
        return $this->aLevelWasAlreadyPushedInThisProcess
            && $levelOfTheCurrentWindow === $this->lastPushedBrightnessStore->lastPushedLevel();
    }
}
