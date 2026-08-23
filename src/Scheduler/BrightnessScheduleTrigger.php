<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Config\Device\BrightnessSchedule;
use Psr\Clock\ClockInterface;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * Wakes the brightness up only when a declared window opens or closes: the level the panel is held
 * at cannot change anywhere else, so a tick between two bounds would push what the panel already
 * holds and cost a write of the scheduler state for nothing.
 */
final class BrightnessScheduleTrigger implements TriggerInterface
{
    /**
     * The run is handed out one second ahead because the scheduler only keeps a run date strictly
     * after the one it asked from.
     */
    private const string DELAY_OF_THE_START_UP_RUN = '+1 second';

    private bool $theStartUpRunWasHandedOut = false;

    public function __construct(
        private readonly BrightnessSchedule $brightnessSchedule,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('at every bound of %s', $this->brightnessSchedule);
    }

    public function getNextRunDate(\DateTimeImmutable $run): \DateTimeImmutable
    {
        // Nothing guarantees the panel was left alone while the client was down, and the next bound
        // can be most of a day away: the first run a process asks for is due right away, so a
        // restart takes the brightness back in hand rather than at the next window.
        //
        // It is dated from the start of the process rather than from the checkpoint left behind, so
        // that a consumer coming back in the middle of the night is held by the sleep schedule like
        // any other run instead of replaying a run dated before the panel went off.
        if (!$this->theStartUpRunWasHandedOut) {
            $this->theStartUpRunWasHandedOut = true;
            $startOfTheProcess = $this->clock->now();

            return ($startOfTheProcess > $run ? $startOfTheProcess : $run)->modify(self::DELAY_OF_THE_START_UP_RUN);
        }

        return $this->brightnessSchedule->nextBoundAfter($run);
    }
}
