<?php

declare(strict_types=1);

namespace App\Config\Sleep;

/**
 * One concrete stretch of darkness, both bounds as absolute instants rather than as times of day.
 */
final readonly class SleepStretch
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
     * The panel is lit again at "end", so a cycle landing exactly there is awake and must run.
     */
    public function covers(\DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && $instant < $this->end;
    }
}
