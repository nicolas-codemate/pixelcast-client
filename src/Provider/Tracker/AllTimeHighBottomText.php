<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;

final class AllTimeHighBottomText
{
    /**
     * What the bottom row shows at a glance. A longer text scrolls instead of being cut, so
     * this is a readability target, not a limit: TrackerPayload holds the only hard one.
     */
    public const int CHARACTERS_READ_WITHOUT_SCROLLING = 10;

    private const string LABEL = 'ATH ';

    private const int TWO_DECIMALS_BELOW = 1_000;

    /**
     * Reordering this breaks condense(), which returns on the first threshold reached.
     */
    private const array MAGNITUDE_SUFFIXES = [
        1_000_000_000_000 => 'T',
        1_000_000_000 => 'B',
        1_000_000 => 'M',
        1_000 => 'K',
    ];

    /**
     * The device draws the bottom row with an ASCII-only font, so a currency whose sign
     * falls outside that range carries no symbol. The price row above already names it.
     */
    private const array CURRENCY_SYMBOLS = [
        'USD' => '$',
    ];

    public static function composeFrom(?float $allTimeHighPrice, ?string $currency = null): ?string
    {
        if (null === $allTimeHighPrice || $allTimeHighPrice <= 0.0) {
            return null;
        }

        $currencySymbol = self::CURRENCY_SYMBOLS[mb_strtoupper($currency ?? '')] ?? '';

        $fullText = self::LABEL.self::writeInFull($allTimeHighPrice).$currencySymbol;
        if (self::fitsTheContract($fullText)) {
            return $fullText;
        }

        $condensedText = self::LABEL.self::condense($allTimeHighPrice).$currencySymbol;

        return self::fitsTheContract($condensedText) ? $condensedText : null;
    }

    /**
     * Writes the high the way the device writes the price row above it, so that comparing the
     * two rows is a digit against digit read.
     */
    private static function writeInFull(float $price): string
    {
        $decimals = $price < self::TWO_DECIMALS_BELOW ? 2 : 0;

        return number_format($price, $decimals, '.', '');
    }

    private static function condense(float $price): string
    {
        foreach (self::MAGNITUDE_SUFFIXES as $threshold => $suffix) {
            if ($price >= $threshold) {
                return self::writeInFull($price / $threshold).$suffix;
            }
        }

        return self::writeInFull($price);
    }

    private static function fitsTheContract(string $bottomText): bool
    {
        return mb_strlen($bottomText) <= TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH;
    }
}
