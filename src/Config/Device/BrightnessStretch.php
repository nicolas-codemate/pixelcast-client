<?php

declare(strict_types=1);

namespace App\Config\Device;

use App\Client\Settings\BrightnessLevel;

/**
 * One concrete stretch the panel is held at a level, both bounds as absolute instants rather than
 * as times of day.
 */
final readonly class BrightnessStretch
{
    private function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public BrightnessLevel $level,
    ) {
    }

    public static function of(\DateTimeImmutable $start, \DateTimeImmutable $end, BrightnessLevel $level): self
    {
        return new self($start, $end, $level);
    }

    /**
     * The level ends at "end", so two windows written back to back never dispute a minute.
     */
    public function covers(\DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && $instant < $this->end;
    }
}
