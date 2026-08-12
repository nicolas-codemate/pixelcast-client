<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
use App\Tracker\AllTimeHigh;

final class AllTimeHighBottomText
{
    /**
     * What the bottom row shows at a glance. A longer text scrolls instead of being cut, so
     * this is a readability target, not a limit: TrackerPayload holds the only hard one.
     */
    public const int CHARACTERS_READ_WITHOUT_SCROLLING = 10;

    private const string LABEL = 'ATH ';

    private const string REACHED_AT_FORMAT = 'm/Y';

    public static function composeFrom(?AllTimeHigh $allTimeHigh): ?string
    {
        if (null === $allTimeHigh || $allTimeHigh->price <= 0.0) {
            return null;
        }

        $highText = self::LABEL.self::writePrice($allTimeHigh->price).BottomTextAmount::currencySymbolOf($allTimeHigh->currency);

        $datedText = $highText.self::writeReachedAt($allTimeHigh->reachedAt);
        if (self::fitsTheContract($datedText)) {
            return $datedText;
        }

        // The date is dropped before the price is: a high without its day still reads.
        return self::fitsTheContract($highText) ? $highText : null;
    }

    /**
     * A price of a thousand or more is condensed on its magnitude, so that a six-digit high leaves
     * the room the day it was reached needs. Below that the high keeps the two decimals the device
     * writes the price row above it with, so that comparing the two rows is a digit against digit read.
     */
    private static function writePrice(float $price): string
    {
        [$scaledPrice, $magnitudeSuffix] = BottomTextAmount::scaleToMagnitude($price);

        return number_format($scaledPrice, '' === $magnitudeSuffix ? 2 : 1, '.', '').$magnitudeSuffix;
    }

    /**
     * The year is written in full because two digits next to a month read as a day. The instant
     * keeps the timezone it was stored in: only a high reached on the turn of a month would then
     * read as the neighbouring one.
     */
    private static function writeReachedAt(?\DateTimeImmutable $reachedAt): string
    {
        return null === $reachedAt ? '' : ' '.$reachedAt->format(self::REACHED_AT_FORMAT);
    }

    private static function fitsTheContract(string $bottomText): bool
    {
        return mb_strlen($bottomText) <= TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH;
    }
}
