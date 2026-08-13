<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Config\Sleep\SleepSchedule;
use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * Holds a sync group to the hours the panel is lit: a run date falling in the dark is neither run
 * then nor replayed later, it is moved to the instant the panel comes back on, so the screen
 * relights on a fresh payload rather than on a wall of stale apps.
 */
final class SleepScheduleTrigger extends AbstractDecoratedTrigger
{
    // Windows touching one another chain their wake-ups, but a schedule leaving no waking hour at
    // all would chain them forever.
    private const int MAXIMUM_CHAINED_SLEEPS = 8;

    public function __construct(
        private readonly TriggerInterface $innerTrigger,
        private readonly SleepSchedule $sleepSchedule,
    ) {
        parent::__construct($innerTrigger);
    }

    public function __toString(): string
    {
        return \sprintf('%s, asleep %s', $this->innerTrigger, $this->sleepSchedule);
    }

    public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
    {
        $nextRun = $this->innerTrigger->getNextRunDate($run);

        for ($chainedSleeps = 0; $chainedSleeps < self::MAXIMUM_CHAINED_SLEEPS; ++$chainedSleeps) {
            if (null === $nextRun) {
                return null;
            }

            $wakeUp = $this->sleepSchedule->endOfTheSleepCovering($nextRun);

            if (null === $wakeUp) {
                return $nextRun;
            }

            $nextRun = $wakeUp;
        }

        return null;
    }
}
