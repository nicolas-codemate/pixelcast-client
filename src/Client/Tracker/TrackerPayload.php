<?php

declare(strict_types=1);

namespace App\Client\Tracker;

use App\Client\Color;

final readonly class TrackerPayload
{
    private const int MAXIMUM_SYMBOL_LENGTH = 7;
    private const int MAXIMUM_CURRENCY_LENGTH = 7;
    private const int MAXIMUM_BOTTOM_TEXT_LENGTH = 31;
    private const int MAXIMUM_SPARKLINE_POINTS = 24;

    /**
     * @param list<float> $sparklinePoints
     */
    public function __construct(
        public string $name,
        public ?string $symbol = null,
        public ?string $iconName = null,
        public ?string $currency = null,
        public ?float $currentValue = null,
        public ?float $changePercentage = null,
        public array $sparklinePoints = [],
        public ?Color $symbolColor = null,
        public ?Color $sparklineColor = null,
        public ?string $bottomText = null,
        public ?int $displayDurationMilliseconds = null,
    ) {
        if ('' === $this->name) {
            throw new \InvalidArgumentException('A tracker needs a non-empty name.');
        }

        if (null !== $this->symbol && mb_strlen($this->symbol) > self::MAXIMUM_SYMBOL_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A tracker symbol holds at most %d characters, got %d.', self::MAXIMUM_SYMBOL_LENGTH, mb_strlen($this->symbol)));
        }

        if (null !== $this->currency && mb_strlen($this->currency) > self::MAXIMUM_CURRENCY_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A tracker currency holds at most %d characters, got %d.', self::MAXIMUM_CURRENCY_LENGTH, mb_strlen($this->currency)));
        }

        if (null !== $this->bottomText && mb_strlen($this->bottomText) > self::MAXIMUM_BOTTOM_TEXT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A tracker bottom text holds at most %d characters, got %d.', self::MAXIMUM_BOTTOM_TEXT_LENGTH, mb_strlen($this->bottomText)));
        }

        if (\count($this->sparklinePoints) > self::MAXIMUM_SPARKLINE_POINTS) {
            throw new \InvalidArgumentException(\sprintf('A tracker sparkline holds at most %d points, got %d.', self::MAXIMUM_SPARKLINE_POINTS, \count($this->sparklinePoints)));
        }
    }

    // The tracker name travels as a query parameter, the only form DELETE /tracker accepts.
    /**
     * @return array{symbol?: string, icon?: string, currency?: string, value?: float, change?: float, sparkline?: list<float>, symbolColor?: string, sparklineColor?: string, bottomText?: string, duration?: int}
     */
    public function toArray(): array
    {
        $payload = [];

        if (null !== $this->symbol) {
            $payload['symbol'] = $this->symbol;
        }

        if (null !== $this->iconName) {
            $payload['icon'] = $this->iconName;
        }

        if (null !== $this->currency) {
            $payload['currency'] = $this->currency;
        }

        if (null !== $this->currentValue) {
            $payload['value'] = $this->currentValue;
        }

        if (null !== $this->changePercentage) {
            $payload['change'] = $this->changePercentage;
        }

        if ([] !== $this->sparklinePoints) {
            $payload['sparkline'] = $this->sparklinePoints;
        }

        if (null !== $this->symbolColor) {
            $payload['symbolColor'] = $this->symbolColor->hexCode;
        }

        if (null !== $this->sparklineColor) {
            $payload['sparklineColor'] = $this->sparklineColor->hexCode;
        }

        if (null !== $this->bottomText) {
            $payload['bottomText'] = $this->bottomText;
        }

        if (null !== $this->displayDurationMilliseconds) {
            $payload['duration'] = $this->displayDurationMilliseconds;
        }

        return $payload;
    }
}
