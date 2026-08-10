<?php

declare(strict_types=1);

namespace App\Provider\Claude;

/**
 * An amount as the usage endpoint states it: minor units, with the currency and the exponent that
 * make them readable sitting on the very same object.
 */
final readonly class ClaudeMoney
{
    private const int MINOR_UNIT_BASE = 10;

    private function __construct(
        public int $amountInMinorUnits,
        public string $currency,
        public int $exponent,
    ) {
    }

    public static function create(int $amountInMinorUnits, string $currency, int $exponent): self
    {
        if ('' === $currency) {
            throw new \InvalidArgumentException('A Claude amount carries a currency.');
        }

        if ($exponent < 0) {
            throw new \InvalidArgumentException(\sprintf('A minor-unit exponent cannot be negative, got %d.', $exponent));
        }

        return new self($amountInMinorUnits, $currency, $exponent);
    }

    public function amount(): float
    {
        return $this->amountInMinorUnits / self::MINOR_UNIT_BASE ** $this->exponent;
    }
}
