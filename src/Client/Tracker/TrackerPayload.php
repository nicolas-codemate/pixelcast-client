<?php

declare(strict_types=1);

namespace App\Client\Tracker;

use App\Client\Color;
use App\Client\StaleBehavior;

final readonly class TrackerPayload
{
    // The device chart is 63 columns wide and plots one point per column.
    public const int MAXIMUM_SPARKLINE_POINTS = 63;
    public const int MAXIMUM_SYMBOL_LENGTH = 31;
    public const int MAXIMUM_BOTTOM_TEXT_LENGTH = 31;

    private const int MAXIMUM_CURRENCY_LENGTH = 7;
    private const int MAXIMUM_SPARKLINE_PERIOD_LENGTH = 4;

    /**
     * @param list<float> $sparklinePoints
     * @param list<float> $volumePoints
     */
    public function __construct(
        public string $name,
        public ?string $symbol = null,
        public ?string $iconName = null,
        public ?string $currency = null,
        public ?float $currentValue = null,
        public ?float $changePercentage = null,
        public array $sparklinePoints = [],
        public array $volumePoints = [],
        public ?Color $symbolColor = null,
        public ?Color $sparklineColor = null,
        public ?string $sparklinePeriod = null,
        public ?string $bottomText = null,
        public ?int $displayDurationMilliseconds = null,
        public ?int $staleAfterInSeconds = null,
        public ?StaleBehavior $staleBehavior = null,
    ) {
        if ('' === $this->name) {
            throw new \InvalidArgumentException('A tracker needs a non-empty name.');
        }

        self::assertLengthWithinLimit('symbol', $this->symbol, self::MAXIMUM_SYMBOL_LENGTH);
        self::assertLengthWithinLimit('currency', $this->currency, self::MAXIMUM_CURRENCY_LENGTH);
        self::assertLengthWithinLimit('sparkline period', $this->sparklinePeriod, self::MAXIMUM_SPARKLINE_PERIOD_LENGTH);
        self::assertLengthWithinLimit('bottom text', $this->bottomText, self::MAXIMUM_BOTTOM_TEXT_LENGTH);

        self::assertPointCountWithinLimit('sparkline', $this->sparklinePoints);
        self::assertPointCountWithinLimit('volume series', $this->volumePoints);
    }

    /**
     * The name is absent on purpose: it travels as a query parameter, the only form DELETE /tracker accepts.
     *
     * @return array{symbol?: string, icon?: string, currency?: string, value?: float, change?: float, sparkline?: list<float>, volumes?: list<float>, symbolColor?: string, sparklineColor?: string, sparklinePeriod?: string, bottomText?: string, duration?: int, staleAfter?: int, staleBehavior?: string}
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

        if ([] !== $this->volumePoints) {
            $payload['volumes'] = $this->volumePoints;
        }

        if (null !== $this->symbolColor) {
            $payload['symbolColor'] = $this->symbolColor->hexCode;
        }

        if (null !== $this->sparklineColor) {
            $payload['sparklineColor'] = $this->sparklineColor->hexCode;
        }

        if (null !== $this->sparklinePeriod) {
            $payload['sparklinePeriod'] = $this->sparklinePeriod;
        }

        if (null !== $this->bottomText) {
            $payload['bottomText'] = $this->bottomText;
        }

        if (null !== $this->displayDurationMilliseconds) {
            $payload['duration'] = $this->displayDurationMilliseconds;
        }

        if (null !== $this->staleAfterInSeconds) {
            $payload['staleAfter'] = $this->staleAfterInSeconds;
        }

        if (null !== $this->staleBehavior) {
            $payload['staleBehavior'] = $this->staleBehavior->value;
        }

        return $payload;
    }

    private static function assertLengthWithinLimit(string $fieldLabel, ?string $value, int $maximumLength): void
    {
        if (null !== $value && mb_strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException(\sprintf('A tracker %s holds at most %d characters, got %d.', $fieldLabel, $maximumLength, mb_strlen($value)));
        }
    }

    /**
     * @param list<float> $points
     */
    private static function assertPointCountWithinLimit(string $fieldLabel, array $points): void
    {
        if (\count($points) > self::MAXIMUM_SPARKLINE_POINTS) {
            throw new \InvalidArgumentException(\sprintf('A tracker %s holds at most %d points, got %d.', $fieldLabel, self::MAXIMUM_SPARKLINE_POINTS, \count($points)));
        }
    }
}
