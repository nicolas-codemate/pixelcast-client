<?php

declare(strict_types=1);

namespace App\Provider\Claude;

use App\Client\Color;

/**
 * The two colour ladders of the Claude gauge: one for the bar, drawn from the percentage, one for
 * the pace note, drawn from the multiplier.
 *
 * The hex codes are the ones the firmware documentation uses in its own example of this gauge, so
 * that the device and the specification agree on what "green" is.
 *
 * Next to the two ladders sit three tints that no reading moves.
 */
final class ClaudeUsageColors
{
    public const string GREEN_HEX_CODE = '#4CAF50';
    public const string YELLOW_HEX_CODE = '#FFC107';
    public const string RED_HEX_CODE = '#F44336';
    public const string TITLE_HEX_CODE = '#D97757';
    public const string ROW_LABEL_HEX_CODE = '#FFFFFF';
    public const string ROW_LABEL_SUFFIX_HEX_CODE = '#888888';

    private const int YELLOW_PERCENT_THRESHOLD = 50;
    private const int RED_PERCENT_THRESHOLD = 80;
    private const float YELLOW_PACE_THRESHOLD = 1.0;
    private const float RED_PACE_THRESHOLD = 1.3;

    public static function titleColor(): Color
    {
        return Color::fromHexCode(self::TITLE_HEX_CODE);
    }

    public static function rowLabelColor(): Color
    {
        return Color::fromHexCode(self::ROW_LABEL_HEX_CODE);
    }

    public static function rowLabelSuffixColor(): Color
    {
        return Color::fromHexCode(self::ROW_LABEL_SUFFIX_HEX_CODE);
    }

    public static function barColorFor(int $percent): Color
    {
        return match (true) {
            $percent < self::YELLOW_PERCENT_THRESHOLD => Color::fromHexCode(self::GREEN_HEX_CODE),
            $percent < self::RED_PERCENT_THRESHOLD => Color::fromHexCode(self::YELLOW_HEX_CODE),
            default => Color::fromHexCode(self::RED_HEX_CODE),
        };
    }

    /**
     * This ladder is independent of the arrow ladder of UsagePace, and the two genuinely disagree
     * between 1.0 and 1.1: a note reading "x1.1>" is coloured yellow. Both come verbatim from the
     * statusline this indicator is ported from, where the arrow and the colour are two separate
     * tests over the same multiplier; merging them would change what the panel shows.
     */
    public static function noteColorFor(UsagePace $pace): Color
    {
        return match (true) {
            $pace->multiplier >= self::RED_PACE_THRESHOLD => Color::fromHexCode(self::RED_HEX_CODE),
            $pace->multiplier > self::YELLOW_PACE_THRESHOLD => Color::fromHexCode(self::YELLOW_HEX_CODE),
            default => Color::fromHexCode(self::GREEN_HEX_CODE),
        };
    }
}
