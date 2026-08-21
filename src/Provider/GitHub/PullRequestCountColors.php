<?php

declare(strict_types=1);

namespace App\Provider\GitHub;

use App\Client\Color;

/**
 * The tint the review count is drawn in, read from the count itself: a backlog that grew past a
 * morning of reviewing has to be legible as such from across the room, before the digit is even
 * read.
 *
 * The three hex codes are the ones the firmware documentation uses in its own examples, so that
 * the device and the specification agree on what "green" is. They are the ladder of the Claude
 * gauge as well, which keeps one meaning of green across the rotation.
 */
final class PullRequestCountColors
{
    public const string GREEN_HEX_CODE = '#4CAF50';
    public const string YELLOW_HEX_CODE = '#FFC107';
    public const string RED_HEX_CODE = '#F44336';

    private const int YELLOW_COUNT_THRESHOLD = 3;
    private const int RED_COUNT_THRESHOLD = 6;

    public static function countColorFor(int $pullRequestCount): Color
    {
        return Color::fromHexCode(match (true) {
            $pullRequestCount >= self::RED_COUNT_THRESHOLD => self::RED_HEX_CODE,
            $pullRequestCount >= self::YELLOW_COUNT_THRESHOLD => self::YELLOW_HEX_CODE,
            default => self::GREEN_HEX_CODE,
        });
    }
}
