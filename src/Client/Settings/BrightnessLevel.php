<?php

declare(strict_types=1);

namespace App\Client\Settings;

final readonly class BrightnessLevel
{
    public const int MINIMUM_LEVEL = 0;
    public const int MAXIMUM_LEVEL = 255;

    private function __construct(
        public int $level,
    ) {
    }

    public static function create(int $level): self
    {
        if ($level < self::MINIMUM_LEVEL || $level > self::MAXIMUM_LEVEL) {
            throw new \InvalidArgumentException(\sprintf('A brightness level must be between %d and %d, got %d.', self::MINIMUM_LEVEL, self::MAXIMUM_LEVEL, $level));
        }

        return new self($level);
    }
}
