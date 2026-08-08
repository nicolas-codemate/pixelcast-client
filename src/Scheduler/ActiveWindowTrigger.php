<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Config\Sync\ActiveWindow;
use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * Holds a sync group to the hours it declared: a run date falling outside the window is pushed
 * to the first cycle following the next opening.
 */
final class ActiveWindowTrigger extends AbstractDecoratedTrigger
{
    // The declared days repeat every week, so a cycle skipping eight openings in a row can never
    // land inside the window at all.
    private const int MAXIMUM_SKIPPED_OPENINGS = 8;

    public function __construct(
        private readonly TriggerInterface $innerTrigger,
        private readonly ActiveWindow $activeWindow,
    ) {
        parent::__construct($innerTrigger);
    }

    public function __toString(): string
    {
        return \sprintf('%s, only %s', $this->innerTrigger, $this->activeWindow);
    }

    public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
    {
        $nextRun = $this->innerTrigger->getNextRunDate($run);

        for ($skippedOpenings = 0; $skippedOpenings < self::MAXIMUM_SKIPPED_OPENINGS; ++$skippedOpenings) {
            if (null === $nextRun || $this->activeWindow->contains($nextRun)) {
                return $nextRun;
            }

            $nextRun = $this->innerTrigger->getNextRunDate($this->activeWindow->nextOpeningAfter($nextRun));
        }

        return null;
    }
}
