<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

/**
 * The two typographic conventions the bottom row of a tracker writes an amount with, shared by the
 * all-time high and the traded volume: a same currency has to carry a same sign on both, and a same
 * magnitude a same letter.
 */
final class BottomTextAmount
{
    /**
     * Reordering this breaks scaleToMagnitude(), which returns on the first threshold reached.
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

    /**
     * The amount brought down to its magnitude, and the letter that names it. Rounding is left to
     * the caller: a high and a volume do not read at the same precision.
     *
     * @return array{float, string}
     */
    public static function scaleToMagnitude(float $amount): array
    {
        foreach (self::MAGNITUDE_SUFFIXES as $threshold => $suffix) {
            if ($amount >= $threshold) {
                return [$amount / $threshold, $suffix];
            }
        }

        return [$amount, ''];
    }

    public static function currencySymbolOf(?string $currency): string
    {
        return self::CURRENCY_SYMBOLS[mb_strtoupper($currency ?? '')] ?? '';
    }
}
