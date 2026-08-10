<?php

declare(strict_types=1);

namespace App\Provider\Claude;

/**
 * How the burn rate of a usage window compares to spending it evenly.
 *
 * A multiplier of 1.0 means the window lands exactly on empty at reset time, 1.2 means twenty
 * percent ahead of that budget.
 */
final readonly class UsagePace
{
    public const string ACCELERATING_ARROW = '^';
    public const string STEADY_ARROW = '>';
    public const string SLOWING_ARROW = 'v';

    /**
     * Below this much elapsed time the divisor is small enough that a few minutes of clock drift
     * move the multiplier by more than the reading is worth.
     */
    public const int MINIMUM_ELAPSED_IN_SECONDS = 3600;

    private const float ACCELERATING_THRESHOLD = 1.1;
    private const float SLOWING_THRESHOLD = 0.9;
    private const float MAXIMUM_DISPLAYED_MULTIPLIER = 99.9;
    private const string NOTE_FORMAT = 'x%.1f%s';
    private const int PERCENT_SCALE = 100;

    private function __construct(
        public float $multiplier,
    ) {
    }

    /**
     * Null means "show the bar alone": the window carries no reading a pace could be drawn from.
     *
     * @param int $windowSeconds the length of the rolling window, which differs per window and is
     *                           therefore never a constant of this class
     */
    public static function compute(int $percent, ?\DateTimeImmutable $resetsAt, int $windowSeconds, \DateTimeImmutable $now): ?self
    {
        // No reset instant, no window to measure against — the normal state of the Fable window.
        if (null === $resetsAt) {
            return null;
        }

        // Nothing consumed yet: a pace of zero says nothing about the rest of the window.
        if ($percent <= 0) {
            return null;
        }

        $remainingInSeconds = $resetsAt->getTimestamp() - $now->getTimestamp();
        $elapsedInSeconds = $windowSeconds - $remainingInSeconds;

        // Too early in the window for the divisor to mean anything.
        if ($elapsedInSeconds <= self::MINIMUM_ELAPSED_IN_SECONDS) {
            return null;
        }

        // The reset is already behind us, so the reading describes a window that no longer exists.
        if ($remainingInSeconds <= 0) {
            return null;
        }

        return new self($percent * $windowSeconds / (self::PERCENT_SCALE * $elapsedInSeconds));
    }

    /**
     * The arrow ladder is deliberately not merged with the colour ladder of ClaudeUsageColors: the
     * two disagree between 1.0 and 1.1, where the note reads as on track yet is coloured yellow.
     * Both are taken verbatim from the statusline this indicator is ported from.
     */
    public function arrow(): string
    {
        return match (true) {
            $this->multiplier > self::ACCELERATING_THRESHOLD => self::ACCELERATING_ARROW,
            $this->multiplier < self::SLOWING_THRESHOLD => self::SLOWING_ARROW,
            default => self::STEADY_ARROW,
        };
    }

    /**
     * The panel has no arrow glyph, hence the ASCII arrows.
     *
     * The displayed multiplier is capped so that a pathological reading cannot outgrow the row's
     * note limit and cost that row its push.
     */
    public function note(): string
    {
        return \sprintf(self::NOTE_FORMAT, min($this->multiplier, self::MAXIMUM_DISPLAYED_MULTIPLIER), $this->arrow());
    }
}
