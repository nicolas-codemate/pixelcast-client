<?php

declare(strict_types=1);

namespace App\Client\Gauge;

use App\Client\Color;

final readonly class GaugeRow
{
    public const int MAXIMUM_LABEL_LENGTH = 11;
    public const int MAXIMUM_INFO_LENGTH = 15;
    public const int MAXIMUM_VALUE_LENGTH = 7;
    public const int MAXIMUM_NOTE_LENGTH = 7;
    public const int MINIMUM_PERCENT = 0;
    public const int MAXIMUM_PERCENT = 100;

    private function __construct(
        public string $label,
        public int $percent,
        public ?string $info,
        public ?string $value,
        public ?string $note,
        public ?Color $barColor,
        public ?Color $noteColor,
    ) {
    }

    public static function create(
        string $label,
        int $percent,
        ?string $info = null,
        ?string $value = null,
        ?string $note = null,
        ?Color $barColor = null,
        ?Color $noteColor = null,
    ): self {
        self::assertLengthWithinLimit('label', $label, self::MAXIMUM_LABEL_LENGTH);
        self::assertLengthWithinLimit('info', $info, self::MAXIMUM_INFO_LENGTH);
        self::assertLengthWithinLimit('value', $value, self::MAXIMUM_VALUE_LENGTH);
        self::assertLengthWithinLimit('note', $note, self::MAXIMUM_NOTE_LENGTH);

        // Clamped rather than refused, as the firmware does on parse: a percentage that lands
        // just outside the range through a rounding edge must not cost the whole gauge its push.
        $clampedPercent = max(self::MINIMUM_PERCENT, min(self::MAXIMUM_PERCENT, $percent));

        return new self(
            label: $label,
            percent: $clampedPercent,
            info: $info,
            value: $value,
            note: $note,
            barColor: $barColor,
            noteColor: $noteColor,
        );
    }

    /**
     * @return array{label: string, info?: string, value?: string, percent: int, note?: string, color?: string, noteColor?: string}
     */
    public function toArray(): array
    {
        $payload = ['label' => $this->label];

        if (null !== $this->info) {
            $payload['info'] = $this->info;
        }

        if (null !== $this->value) {
            $payload['value'] = $this->value;
        }

        // Always emitted: 0 is a reading of its own, an empty bar, not a missing one.
        $payload['percent'] = $this->percent;

        if (null !== $this->note) {
            $payload['note'] = $this->note;
        }

        if (null !== $this->barColor) {
            $payload['color'] = $this->barColor->hexCode;
        }

        if (null !== $this->noteColor) {
            $payload['noteColor'] = $this->noteColor->hexCode;
        }

        return $payload;
    }

    private static function assertLengthWithinLimit(string $fieldLabel, ?string $value, int $maximumLength): void
    {
        if (null !== $value && mb_strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException(\sprintf('A gauge row %s holds at most %d characters, got %d.', $fieldLabel, $maximumLength, mb_strlen($value)));
        }
    }
}
