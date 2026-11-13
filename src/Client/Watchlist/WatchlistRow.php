<?php

declare(strict_types=1);

namespace App\Client\Watchlist;

use App\Client\Color;

final readonly class WatchlistRow
{
    public const int MAXIMUM_SYMBOL_LENGTH = 15;
    public const int MAXIMUM_CURRENCY_LENGTH = 7;

    private function __construct(
        public string $symbol,
        public float $value,
        public float $changePercentage,
        public ?string $currency,
        public ?string $iconName,
        public ?Color $symbolColor,
    ) {
    }

    public static function create(
        string $symbol,
        float $value,
        float $changePercentage,
        ?string $currency = null,
        ?string $iconName = null,
        ?Color $symbolColor = null,
    ): self {
        self::assertLengthWithinLimit('symbol', $symbol, self::MAXIMUM_SYMBOL_LENGTH);
        self::assertLengthWithinLimit('currency', $currency, self::MAXIMUM_CURRENCY_LENGTH);

        return new self(
            symbol: $symbol,
            value: $value,
            changePercentage: $changePercentage,
            currency: $currency,
            iconName: $iconName,
            symbolColor: $symbolColor,
        );
    }

    /**
     * @return array{symbol: string, icon?: string, value: float, currency?: string, change: float, symbolColor?: string}
     */
    public function toArray(): array
    {
        $payload = ['symbol' => $this->symbol];

        if (null !== $this->iconName) {
            $payload['icon'] = $this->iconName;
        }

        $payload['value'] = $this->value;

        if (null !== $this->currency) {
            $payload['currency'] = $this->currency;
        }

        $payload['change'] = $this->changePercentage;

        if (null !== $this->symbolColor) {
            $payload['symbolColor'] = $this->symbolColor->hexCode;
        }

        return $payload;
    }

    private static function assertLengthWithinLimit(string $fieldLabel, ?string $value, int $maximumLength): void
    {
        if (null !== $value && mb_strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException(\sprintf('A watchlist row %s holds at most %d characters, got %d.', $fieldLabel, $maximumLength, mb_strlen($value)));
        }
    }
}
