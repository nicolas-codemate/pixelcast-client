<?php

declare(strict_types=1);

namespace App\Tracker\History;

use App\Tracker\AllTimeHigh;

/**
 * Reads the end-of-day series Boursorama serves, for the tracker provider that gets thirty bars on
 * every sync as well as for the deep-history source that gets twenty years of them.
 */
final class BoursoramaQuoteSeries
{
    private const int SECONDS_PER_DAY = 86_400;

    /**
     * @param array<array-key, mixed> $decodedResponse
     *
     * @return array<array-key, mixed>
     */
    public static function readBars(array $decodedResponse): array
    {
        $quoteBlock = $decodedResponse['d'] ?? null;
        $quoteBars = \is_array($quoteBlock) ? ($quoteBlock['QuoteTab'] ?? null) : null;

        return \is_array($quoteBars) ? $quoteBars : [];
    }

    /**
     * The highest price the series shows is the highest session high, not the last close: an ETF
     * that touches 620 during a session and closes at 610 did reach 620. A bar without a high falls
     * back on its close, and the day of the winning bar travels as the moment the price was reached,
     * since that bar can be years older than the moment the series is read.
     *
     * @param array<array-key, mixed> $quoteBars
     */
    public static function readAllTimeHigh(
        array $quoteBars,
        string $syncType,
        string $symbol,
        string $currency,
        \DateTimeImmutable $observedAt,
    ): ?AllTimeHigh {
        $highestPrice = null;
        $highestBar = null;

        foreach ($quoteBars as $quoteBar) {
            if (!\is_array($quoteBar)) {
                continue;
            }

            $sessionHigh = self::readNumber($quoteBar, 'h') ?? self::readNumber($quoteBar, 'c');
            if (null === $sessionHigh) {
                continue;
            }

            if (null !== $highestPrice && $sessionHigh <= $highestPrice) {
                continue;
            }

            $highestPrice = $sessionHigh;
            $highestBar = $quoteBar;
        }

        if (null === $highestPrice || null === $highestBar) {
            return null;
        }

        return new AllTimeHigh(
            $syncType,
            $symbol,
            $currency,
            $highestPrice,
            reachedAt: self::readDayOf($highestBar),
            observedAt: $observedAt,
        );
    }

    /**
     * Each bar carries its day as a count of days since the Unix epoch.
     *
     * @param array<array-key, mixed> $quoteBar
     */
    public static function readDayOf(array $quoteBar): ?\DateTimeImmutable
    {
        $dayCountSinceEpoch = self::readNumber($quoteBar, 'd');
        if (null === $dayCountSinceEpoch) {
            return null;
        }

        return new \DateTimeImmutable('@'.((int) $dayCountSinceEpoch * self::SECONDS_PER_DAY));
    }

    /**
     * @param array<array-key, mixed> $block
     */
    private static function readNumber(array $block, string $key): ?float
    {
        $value = $block[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
