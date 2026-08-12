<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

final class TradedVolumeBottomText
{
    private const string LABEL = 'Volume : ';

    private const int SINGLE_DECIMAL_BELOW = 10;

    public static function composeFrom(?float $volumeOver24Hours, ?string $currency = null): ?string
    {
        if (null === $volumeOver24Hours || $volumeOver24Hours <= 0.0) {
            return null;
        }

        return self::LABEL.self::condense($volumeOver24Hours).BottomTextAmount::currencySymbolOf($currency);
    }

    private static function condense(float $volume): string
    {
        [$scaledVolume, $magnitudeSuffix] = BottomTextAmount::scaleToMagnitude($volume);

        return number_format($scaledVolume, $scaledVolume < self::SINGLE_DECIMAL_BELOW ? 1 : 0, '.', '').$magnitudeSuffix;
    }
}
