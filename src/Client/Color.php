<?php

declare(strict_types=1);

namespace App\Client;

final readonly class Color
{
    private const int MINIMUM_COMPONENT_VALUE = 0;
    private const int MAXIMUM_COMPONENT_VALUE = 255;
    private const int MINIMUM_PACKED_VALUE = 0;
    private const int MAXIMUM_PACKED_VALUE = 0xFFFFFF;
    private const string HEX_CODE_PATTERN = '/^#?[0-9a-fA-F]{6}\z/';

    private function __construct(
        public string $hexCode,
    ) {
    }

    public static function fromHexCode(string $hexCode): self
    {
        if (1 !== preg_match(self::HEX_CODE_PATTERN, $hexCode)) {
            throw new \InvalidArgumentException(\sprintf('A color hex code must hold 6 hexadecimal digits, got "%s".', $hexCode));
        }

        return new self('#'.strtoupper(ltrim($hexCode, '#')));
    }

    public static function fromRgbComponents(int $red, int $green, int $blue): self
    {
        self::assertComponentIsInRange('red', $red);
        self::assertComponentIsInRange('green', $green);
        self::assertComponentIsInRange('blue', $blue);

        return new self(\sprintf('#%02X%02X%02X', $red, $green, $blue));
    }

    public static function fromPackedRgb(int $packedRgb): self
    {
        if ($packedRgb < self::MINIMUM_PACKED_VALUE || $packedRgb > self::MAXIMUM_PACKED_VALUE) {
            throw new \InvalidArgumentException(\sprintf('A packed color must be between %d and %d, got %d.', self::MINIMUM_PACKED_VALUE, self::MAXIMUM_PACKED_VALUE, $packedRgb));
        }

        return new self(\sprintf('#%06X', $packedRgb));
    }

    private static function assertComponentIsInRange(string $componentName, int $componentValue): void
    {
        if ($componentValue < self::MINIMUM_COMPONENT_VALUE || $componentValue > self::MAXIMUM_COMPONENT_VALUE) {
            throw new \InvalidArgumentException(\sprintf('The %s component must be between %d and %d, got %d.', $componentName, self::MINIMUM_COMPONENT_VALUE, self::MAXIMUM_COMPONENT_VALUE, $componentValue));
        }
    }
}
