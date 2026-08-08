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

            $nextRun = $this->innerTrigger->getNextRunDate(self::instantJustBefore($this->activeWindow->nextOpeningAfter($nextRun)));
        }

        return null;
    }

    /**
     * The inner trigger only answers with dates strictly after the one it is given, so the opening
     * itself has to be asked for from just before it: the opening bound is inclusive, and a cycle
     * landing exactly on it must fire then rather than one interval later.
     */
    private static function instantJustBefore(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->modify('-1 microsecond');
    }
}
