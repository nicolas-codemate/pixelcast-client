<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\Color;
use App\Config\Exception\PixelCastConfigException;

final class SyncOptionReader
{
    private const string TIME_OF_DAY_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * A key left out of the file and a key written with no value both mean the option is absent.
     *
     * @param array<string, mixed> $options
     */
    public static function isDeclared(array $options, string $key): bool
    {
        return \array_key_exists($key, $options) && null !== $options[$key];
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function requireString(array $options, string $key, string $parentPath): string
    {
        $optionPath = self::optionPath($parentPath, $key);
        $value = self::requireOption($options, $key, $parentPath);

        if (!\is_string($value)) {
            throw PixelCastConfigException::invalidValue($optionPath, 'expected a string');
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            throw PixelCastConfigException::invalidValue($optionPath, 'must not be empty');
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function optionalString(array $options, string $key, string $parentPath): ?string
    {
        if (!self::isDeclared($options, $key)) {
            return null;
        }

        return self::requireString($options, $key, $parentPath);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function optionalColor(array $options, string $key, string $parentPath): ?Color
    {
        $hexCode = self::optionalString($options, $key, $parentPath);

        if (null === $hexCode) {
            return null;
        }

        try {
            return Color::fromHexCode($hexCode);
        } catch (\InvalidArgumentException $conversionError) {
            throw PixelCastConfigException::invalidValue(self::optionPath($parentPath, $key), 'expected a hex color like "#00D4FF"', $conversionError);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function requireTimeOfDay(array $options, string $key, string $parentPath): string
    {
        $timeOfDay = self::requireString($options, $key, $parentPath);

        if (1 !== preg_match(self::TIME_OF_DAY_PATTERN, $timeOfDay)) {
            throw PixelCastConfigException::invalidValue(self::optionPath($parentPath, $key), \sprintf('expected a time of day like "09:00", got "%s"', $timeOfDay));
        }

        return $timeOfDay;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function requireFloat(array $options, string $key, string $parentPath): float
    {
        $value = self::requireOption($options, $key, $parentPath);

        if (!\is_float($value) && !\is_int($value)) {
            throw PixelCastConfigException::invalidValue(self::optionPath($parentPath, $key), 'expected a number');
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function requireBool(array $options, string $key, string $parentPath): bool
    {
        $value = self::requireOption($options, $key, $parentPath);

        if (!\is_bool($value)) {
            throw PixelCastConfigException::invalidValue(self::optionPath($parentPath, $key), 'expected a boolean');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function optionalBool(array $options, string $key, string $parentPath): ?bool
    {
        if (!self::isDeclared($options, $key)) {
            return null;
        }

        return self::requireBool($options, $key, $parentPath);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function optionalInt(array $options, string $key, string $parentPath, int $minimum, int $maximum): ?int
    {
        if (!self::isDeclared($options, $key)) {
            return null;
        }

        $optionPath = self::optionPath($parentPath, $key);
        $value = $options[$key];

        if (!\is_int($value)) {
            throw PixelCastConfigException::invalidValue($optionPath, 'expected an integer');
        }

        if ($value < $minimum || $value > $maximum) {
            throw PixelCastConfigException::invalidValue($optionPath, \sprintf('expected an integer between %d and %d, got %d', $minimum, $maximum, $value));
        }

        return $value;
    }

    /**
     * @template TBackedEnum of \BackedEnum
     *
     * @param array<string, mixed> $options
     * @param class-string<TBackedEnum> $enumClass
     *
     * @return TBackedEnum
     */
    public static function requireEnum(array $options, string $key, string $parentPath, string $enumClass): \BackedEnum
    {
        $matchedCase = $enumClass::tryFrom(self::requireString($options, $key, $parentPath));

        if (null === $matchedCase) {
            throw PixelCastConfigException::invalidValue(self::optionPath($parentPath, $key), \sprintf('expected one of: %s', implode(', ', array_column($enumClass::cases(), 'value'))));
        }

        return $matchedCase;
    }

    /**
     * @template TBackedEnum of \BackedEnum
     *
     * @param array<string, mixed> $options
     * @param list<TBackedEnum> $acceptedCases the cases this option accepts, which may be narrower than the whole enum
     *
     * @return TBackedEnum|null
     */
    public static function optionalEnum(array $options, string $key, string $parentPath, array $acceptedCases): ?\BackedEnum
    {
        $rawValue = self::optionalString($options, $key, $parentPath);

        if (null === $rawValue) {
            return null;
        }

        foreach ($acceptedCases as $acceptedCase) {
            if ($acceptedCase->value === $rawValue) {
                return $acceptedCase;
            }
        }

        throw PixelCastConfigException::invalidValue(self::optionPath($parentPath, $key), \sprintf('expected one of: %s', implode(', ', array_column($acceptedCases, 'value'))));
    }

    /**
     * @template TBackedEnum of \BackedEnum
     *
     * @param array<string, mixed> $options
     * @param class-string<TBackedEnum> $enumClass
     *
     * @return list<TBackedEnum>
     */
    public static function optionalEnumList(array $options, string $key, string $parentPath, string $enumClass): array
    {
        if (!self::isDeclared($options, $key)) {
            return [];
        }

        $optionPath = self::optionPath($parentPath, $key);
        $value = $options[$key];

        if (!\is_array($value) || !array_is_list($value)) {
            throw PixelCastConfigException::invalidValue($optionPath, 'expected a list');
        }

        $matchedCases = [];
        foreach ($value as $index => $entry) {
            $matchedCase = \is_string($entry) ? $enumClass::tryFrom($entry) : null;

            if (null === $matchedCase) {
                throw PixelCastConfigException::invalidValue(\sprintf('%s[%d]', $optionPath, $index), \sprintf('expected one of: %s', implode(', ', array_column($enumClass::cases(), 'value'))));
            }

            $matchedCases[] = $matchedCase;
        }

        return $matchedCases;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    public static function requireListOfMaps(array $options, string $key, string $parentPath): array
    {
        $optionPath = self::optionPath($parentPath, $key);
        $value = self::requireOption($options, $key, $parentPath);

        if (!\is_array($value) || !array_is_list($value)) {
            throw PixelCastConfigException::invalidValue($optionPath, 'expected a list');
        }

        $maps = [];
        foreach ($value as $index => $entry) {
            $maps[] = self::asStringKeyedMap($entry, \sprintf('%s[%d]', $optionPath, $index));
        }

        return $maps;
    }

    /**
     * @return array<string, mixed>
     */
    public static function asStringKeyedMap(mixed $entry, string $entryPath): array
    {
        if (!\is_array($entry)) {
            throw PixelCastConfigException::invalidValue($entryPath, 'expected a key-value map');
        }

        $stringKeyed = [];
        foreach ($entry as $entryKey => $entryValue) {
            if (!\is_string($entryKey)) {
                throw PixelCastConfigException::invalidValue($entryPath, 'expected a key-value map');
            }
            $stringKeyed[$entryKey] = $entryValue;
        }

        return $stringKeyed;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function requireOption(array $options, string $key, string $parentPath): mixed
    {
        if (!\array_key_exists($key, $options)) {
            throw PixelCastConfigException::missingKey(self::optionPath($parentPath, $key));
        }

        return $options[$key];
    }

    private static function optionPath(string $parentPath, string $key): string
    {
        return $parentPath.'.'.$key;
    }
}
