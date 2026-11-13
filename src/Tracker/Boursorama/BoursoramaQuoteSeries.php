<?php

declare(strict_types=1);

namespace App\Tracker\Boursorama;

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
     * The daily series sometimes loses the last sessions before the running one, and its bar before
     * last is then days older than the eve. The previous session block keeps the right close even
     * then, while its day follows the gap: the day is not read for that reason.
     *
     * @param array<array-key, mixed> $decodedResponse
     */
    public static function readSessionCloses(array $decodedResponse): ?BoursoramaSessionCloses
    {
        $quoteBlock = $decodedResponse['d'] ?? null;
        if (!\is_array($quoteBlock)) {
            return null;
        }

        $previousSession = $quoteBlock['qv'] ?? null;
        $runningSession = $quoteBlock['qd'] ?? null;
        $previousSessionClose = \is_array($previousSession) ? self::readNumber($previousSession, 'c') : null;
        $runningSessionPrice = \is_array($runningSession) ? self::readNumber($runningSession, 'c') : null;

        if (null === $previousSessionClose || null === $runningSessionPrice || 0.0 === $previousSessionClose) {
            return null;
        }

        // An American session in progress has no block of its own yet: the running session block
        // is still the last closed one, and the previous one repeats its close as the reference of
        // the day. The price of the day then only exists in the intraday ticks.
        $latestTickPrice = self::readLatestTickPrice($quoteBlock);
        if ($previousSessionClose === $runningSessionPrice && null !== $latestTickPrice) {
            return new BoursoramaSessionCloses($runningSessionPrice, $latestTickPrice);
        }

        return new BoursoramaSessionCloses($previousSessionClose, $runningSessionPrice);
    }

    /**
     * @param array<array-key, mixed> $quoteBlock
     */
    private static function readLatestTickPrice(array $quoteBlock): ?float
    {
        $ticks = $quoteBlock['QuoteTab'] ?? null;
        $latestTick = \is_array($ticks) ? end($ticks) : null;

        return \is_array($latestTick) ? self::readNumber($latestTick, 'c') : null;
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
        $allTimeHigh = null;

        foreach ($quoteBars as $quoteBar) {
            if (!\is_array($quoteBar)) {
                continue;
            }

            $sessionHigh = self::readNumber($quoteBar, 'h') ?? self::readNumber($quoteBar, 'c');
            if (null === $sessionHigh) {
                continue;
            }

            if (null !== $allTimeHigh && $sessionHigh <= $allTimeHigh->price) {
                continue;
            }

            $allTimeHigh = new AllTimeHigh(
                $syncType,
                $symbol,
                $currency,
                $sessionHigh,
                reachedAt: self::readDayOf($quoteBar),
                observedAt: $observedAt,
            );
        }

        return $allTimeHigh;
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
