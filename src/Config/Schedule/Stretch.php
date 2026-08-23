<?php

declare(strict_types=1);

namespace App\Config\Schedule;

/**
 * One concrete stretch of the day a window opens, both bounds as absolute instants rather than as
 * times of day.
 */
final readonly class Stretch
{
    private function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public static function of(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    /**
     * The stretch is over at "end", so two windows written back to back never dispute a minute.
     */
    public function covers(\DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && $instant < $this->end;
    }
}
