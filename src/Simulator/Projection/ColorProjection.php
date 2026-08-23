<?php

declare(strict_types=1);

namespace App\Simulator\Projection;

use App\Client\Color;

/**
 * The device contract answers with colors as hex strings, whichever of the three accepted
 * forms they were pushed in.
 */
final class ColorProjection
{
    public static function hexCodeOf(mixed $pushedColor): ?string
    {
        try {
            return self::colorOf($pushedColor)?->hexCode;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A color that reads as none of the three accepted forms is dropped, because a response schema
     * allows only a hex string there and requires none of the color fields.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $fields
     *
     * @return array<TKey, mixed>
     */
    public static function withColorFieldsAsHexCodes(array $fields, string ...$colorFieldNames): array
    {
        foreach ($colorFieldNames as $colorFieldName) {
            if (!\array_key_exists($colorFieldName, $fields)) {
                continue;
            }

            $hexCode = self::hexCodeOf($fields[$colorFieldName]);
            if (null === $hexCode) {
                unset($fields[$colorFieldName]);

                continue;
            }

            $fields[$colorFieldName] = $hexCode;
        }

        return $fields;
    }

    private static function colorOf(mixed $pushedColor): ?Color
    {
        if (\is_string($pushedColor)) {
            return Color::fromHexCode($pushedColor);
        }

        if (\is_int($pushedColor)) {
            return Color::fromPackedRgb($pushedColor);
        }

        if (!\is_array($pushedColor) || !array_is_list($pushedColor) || 3 !== \count($pushedColor)) {
            return null;
        }

        [$red, $green, $blue] = $pushedColor;
        if (!\is_int($red) || !\is_int($green) || !\is_int($blue)) {
            return null;
        }

        return Color::fromRgbComponents($red, $green, $blue);
    }
}
