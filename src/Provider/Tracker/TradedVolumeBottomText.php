<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

final class TradedVolumeBottomText
{
    private const string LABEL = 'Volume : ';

    /**
     * Reordering this breaks composeFrom(), which returns on the first threshold reached.
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

    private const int SINGLE_DECIMAL_BELOW = 10;

    public static function composeFrom(?float $volumeOver24Hours, ?string $currency = null): ?string
    {
        if (null === $volumeOver24Hours || $volumeOver24Hours <= 0.0) {
            return null;
        }

        $currencySymbol = self::CURRENCY_SYMBOLS[mb_strtoupper($currency ?? '')] ?? '';

        return self::LABEL.self::condense($volumeOver24Hours).$currencySymbol;
    }

    private static function condense(float $volume): string
    {
        foreach (self::MAGNITUDE_SUFFIXES as $threshold => $suffix) {
            if ($volume >= $threshold) {
                return self::scaleAndRound($volume, $threshold).$suffix;
            }
        }

        return self::scaleAndRound($volume, 1);
    }

    private static function scaleAndRound(float $value, int $divisor): string
    {
        $scaledValue = $value / $divisor;
        $decimals = $scaledValue < self::SINGLE_DECIMAL_BELOW ? 1 : 0;

        return number_format($scaledValue, $decimals, '.', '');
    }
}
