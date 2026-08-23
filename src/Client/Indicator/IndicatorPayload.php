<?php

declare(strict_types=1);

namespace App\Client\Indicator;

use App\Client\Color;

final readonly class IndicatorPayload
{
    private function __construct(
        public IndicatorMode $mode,
        public ?Color $color,
        public ?int $blinkIntervalMilliseconds,
        public ?int $fadePeriodMilliseconds,
    ) {
    }

    public static function create(
        IndicatorMode $mode,
        ?Color $color = null,
        ?int $blinkIntervalMilliseconds = null,
        ?int $fadePeriodMilliseconds = null,
    ): self {
        self::assertRhythmMatchesMode($mode, IndicatorMode::Blink, 'blink interval', $blinkIntervalMilliseconds);
        self::assertRhythmMatchesMode($mode, IndicatorMode::Fade, 'fade period', $fadePeriodMilliseconds);
        self::assertTurnedOffIndicatorCarriesNoColor($mode, $color);

        return new self($mode, $color, $blinkIntervalMilliseconds, $fadePeriodMilliseconds);
    }

    /**
     * @return array{mode: string, color?: string, blinkInterval?: int, fadePeriod?: int}
     */
    public function toArray(): array
    {
        $payload = ['mode' => $this->mode->value];

        if (null !== $this->color) {
            $payload['color'] = $this->color->hexCode;
        }

        if (null !== $this->blinkIntervalMilliseconds) {
            $payload['blinkInterval'] = $this->blinkIntervalMilliseconds;
        }

        if (null !== $this->fadePeriodMilliseconds) {
            $payload['fadePeriod'] = $this->fadePeriodMilliseconds;
        }

        return $payload;
    }

    private static function assertRhythmMatchesMode(IndicatorMode $mode, IndicatorMode $owningMode, string $rhythmName, ?int $milliseconds): void
    {
        if (null === $milliseconds) {
            return;
        }

        if ($mode !== $owningMode) {
            throw new \InvalidArgumentException(\sprintf('A %s only belongs to the "%s" mode, got "%s".', $rhythmName, $owningMode->value, $mode->value));
        }

        if ($milliseconds <= 0) {
            throw new \InvalidArgumentException(\sprintf('A %s must be a positive number of milliseconds, got %d.', $rhythmName, $milliseconds));
        }
    }

    /**
     * The opposite is not checked: a lit mode sent without a color keeps the color the slot already
     * carries (firmware 1.10.1), which is how a caller changes only the rhythm of a lit indicator.
     */
    private static function assertTurnedOffIndicatorCarriesNoColor(IndicatorMode $mode, ?Color $color): void
    {
        if (!$mode->lightsTheIndicator() && null !== $color) {
            throw new \InvalidArgumentException(\sprintf('The "%s" mode turns the indicator off, it carries no color.', $mode->value));
        }
    }
}
