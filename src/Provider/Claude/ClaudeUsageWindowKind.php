<?php

declare(strict_types=1);

namespace App\Provider\Claude;

/**
 * The discriminator carried by every entry of the usage endpoint's `limits[]` array.
 */
enum ClaudeUsageWindowKind: string
{
    case Session = 'session';
    case WeeklyAll = 'weekly_all';
    case WeeklyScoped = 'weekly_scoped';

    private const int SESSION_WINDOW_IN_SECONDS = 18000;
    private const int WEEKLY_WINDOW_IN_SECONDS = 604800;

    /**
     * The length of the rolling window the percentage is measured over. It travels with the kind so
     * that no caller can pair a percentage with the wrong window length.
     */
    public function secondsInWindow(): int
    {
        return match ($this) {
            self::Session => self::SESSION_WINDOW_IN_SECONDS,
            self::WeeklyAll, self::WeeklyScoped => self::WEEKLY_WINDOW_IN_SECONDS,
        };
    }
}
