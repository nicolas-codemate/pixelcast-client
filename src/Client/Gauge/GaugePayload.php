<?php

declare(strict_types=1);

namespace App\Client\Gauge;

use App\Client\StaleBehavior;
use App\Client\Text\PolymorphicTextField;

final readonly class GaugePayload
{
    public const int MAXIMUM_NAME_LENGTH = 15;
    public const int MAXIMUM_TITLE_LENGTH = 31;
    public const int MAXIMUM_TITLE_SEGMENTS = 4;
    public const int MAXIMUM_ROWS = 9;

    /**
     * @param list<GaugeRow> $rows
     */
    private function __construct(
        public string $name,
        public array $rows,
        public ?PolymorphicTextField $title,
        public ?string $iconName,
        public ?int $displayDurationMilliseconds,
        public ?int $staleAfterInSeconds,
        public ?StaleBehavior $staleBehavior,
    ) {
    }

    /**
     * @param list<GaugeRow> $rows
     */
    public static function create(
        string $name,
        array $rows,
        string|PolymorphicTextField|null $title = null,
        ?string $iconName = null,
        ?int $displayDurationMilliseconds = null,
        ?int $staleAfterInSeconds = null,
        ?StaleBehavior $staleBehavior = null,
    ): self {
        if ('' === $name) {
            throw new \InvalidArgumentException('A gauge needs a non-empty name.');
        }

        self::assertLengthWithinLimit('name', $name, self::MAXIMUM_NAME_LENGTH);

        $titleField = \is_string($title) ? PolymorphicTextField::fromPlainText($title) : $title;
        $titleField?->assertFitsWithin('gauge title', self::MAXIMUM_TITLE_LENGTH, self::MAXIMUM_TITLE_SEGMENTS);

        if (\count($rows) > self::MAXIMUM_ROWS) {
            throw new \InvalidArgumentException(\sprintf('A gauge carries at most %d rows, got %d.', self::MAXIMUM_ROWS, \count($rows)));
        }

        return new self(
            name: $name,
            rows: $rows,
            title: $titleField,
            iconName: $iconName,
            displayDurationMilliseconds: $displayDurationMilliseconds,
            staleAfterInSeconds: $staleAfterInSeconds,
            staleBehavior: $staleBehavior,
        );
    }

    /**
     * The name is absent on purpose: it travels as a query parameter, the only form DELETE /gauge accepts.
     *
     * @return array{title?: string|list<array{t: string, c: string}>, icon?: string, rows: list<array{label: string|list<array{t: string, c: string}>, info?: string, value?: string, percent: int, note?: string, color?: string, noteColor?: string}>, duration?: int, staleAfter?: int, staleBehavior?: string}
     */
    public function toArray(): array
    {
        $payload = [];

        if (null !== $this->title) {
            $payload['title'] = $this->title->toPayloadValue();
        }

        if (null !== $this->iconName) {
            $payload['icon'] = $this->iconName;
        }

        // Always emitted: the firmware replaces the stored rows whole, so an empty list is the
        // instruction that clears them rather than an absent key that leaves them untouched.
        $payload['rows'] = array_map(
            static fn (GaugeRow $row): array => $row->toArray(),
            $this->rows,
        );

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
            throw new \InvalidArgumentException(\sprintf('A gauge %s holds at most %d characters, got %d.', $fieldLabel, $maximumLength, mb_strlen($value)));
        }
    }
}
