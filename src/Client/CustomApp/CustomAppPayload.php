<?php

declare(strict_types=1);

namespace App\Client\CustomApp;

use App\Client\Color;
use App\Client\StaleBehavior;
use App\Client\Text\PolymorphicTextField;

final readonly class CustomAppPayload
{
    public const int MAXIMUM_TEXT_LENGTH = 63;
    public const int MAXIMUM_TEXT_SEGMENTS = 8;
    public const int MAXIMUM_LABEL_LENGTH = 31;
    public const int MAXIMUM_LABEL_SEGMENTS = 8;
    public const int MINIMUM_ZONES = 2;
    public const int MAXIMUM_ZONES = 4;

    /**
     * What the device does when the keys stay out of the body: a custom app never goes stale, and
     * would leave the rotation rather than dim if it did.
     */
    public const int DEVICE_DEFAULT_STALE_AFTER_IN_SECONDS = 0;
    public const StaleBehavior DEVICE_DEFAULT_STALE_BEHAVIOR = StaleBehavior::Hide;

    /**
     * @param list<Zone> $zones
     */
    private function __construct(
        public string $name,
        public ?PolymorphicTextField $text,
        public ?string $iconName,
        public ?PolymorphicTextField $label,
        public ?Color $color,
        public array $zones,
        public ?int $displayDurationMilliseconds,
        public ?int $staleAfterInSeconds,
        public ?StaleBehavior $staleBehavior,
    ) {
    }

    public static function createSingleZone(
        string $name,
        string|PolymorphicTextField|null $text = null,
        ?string $iconName = null,
        string|PolymorphicTextField|null $label = null,
        ?Color $color = null,
        ?int $displayDurationMilliseconds = null,
        ?int $staleAfterInSeconds = null,
        ?StaleBehavior $staleBehavior = null,
    ): self {
        self::assertNameIsNotEmpty($name);

        $textField = \is_string($text) ? PolymorphicTextField::fromPlainText($text) : $text;
        $textField?->assertFitsWithin('custom app text', self::MAXIMUM_TEXT_LENGTH, self::MAXIMUM_TEXT_SEGMENTS);

        $labelField = \is_string($label) ? PolymorphicTextField::fromPlainText($label) : $label;
        $labelField?->assertFitsWithin('custom app label', self::MAXIMUM_LABEL_LENGTH, self::MAXIMUM_LABEL_SEGMENTS);

        self::assertStaleBehaviorIsRenderable($staleBehavior);

        return new self(
            name: $name,
            text: $textField,
            iconName: $iconName,
            label: $labelField,
            color: $color,
            zones: [],
            displayDurationMilliseconds: $displayDurationMilliseconds,
            staleAfterInSeconds: $staleAfterInSeconds,
            staleBehavior: $staleBehavior,
        );
    }

    /**
     * @param list<Zone> $zones
     */
    public static function createMultiZone(
        string $name,
        array $zones,
        ?int $displayDurationMilliseconds = null,
        ?int $staleAfterInSeconds = null,
        ?StaleBehavior $staleBehavior = null,
    ): self {
        self::assertNameIsNotEmpty($name);
        self::assertZoneCountWithinBounds(\count($zones));
        self::assertStaleBehaviorIsRenderable($staleBehavior);

        return new self(
            name: $name,
            text: null,
            iconName: null,
            label: null,
            color: null,
            zones: $zones,
            displayDurationMilliseconds: $displayDurationMilliseconds,
            staleAfterInSeconds: $staleAfterInSeconds,
            staleBehavior: $staleBehavior,
        );
    }

    /**
     * The name is absent on purpose: it travels as a query parameter.
     *
     * @return array{zones?: list<array{text?: string|list<array{t: string, c: string}>, icon?: string, label?: string|list<array{t: string, c: string}>, color?: string}>, text?: string|list<array{t: string, c: string}>, icon?: string, label?: string|list<array{t: string, c: string}>, color?: string, duration?: int, staleAfter?: int, staleBehavior?: string}
     */
    public function toArray(): array
    {
        $payload = [];

        if ([] !== $this->zones) {
            $payload['zones'] = array_map(
                static fn (Zone $zone): array => $zone->toArray(),
                $this->zones,
            );
        }

        if (null !== $this->text) {
            $payload['text'] = $this->text->toPayloadValue();
        }

        if (null !== $this->iconName) {
            $payload['icon'] = $this->iconName;
        }

        if (null !== $this->label) {
            $payload['label'] = $this->label->toPayloadValue();
        }

        if (null !== $this->color) {
            $payload['color'] = $this->color->hexCode;
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

    private static function assertNameIsNotEmpty(string $name): void
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A custom app needs a non-empty name.');
        }
    }

    private static function assertZoneCountWithinBounds(int $zoneCount): void
    {
        if ($zoneCount < self::MINIMUM_ZONES || $zoneCount > self::MAXIMUM_ZONES) {
            throw new \InvalidArgumentException(\sprintf('A multi-zone custom app carries between %d and %d zones, got %d.', self::MINIMUM_ZONES, self::MAXIMUM_ZONES, $zoneCount));
        }
    }

    private static function assertStaleBehaviorIsRenderable(?StaleBehavior $staleBehavior): void
    {
        if (null === $staleBehavior || \in_array($staleBehavior, StaleBehavior::ACCEPTED_OUTSIDE_TRACKER_AND_GAUGE, true)) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf('A custom app renders no "%s" stale behavior, only %s.', $staleBehavior->value, implode(', ', array_column(StaleBehavior::ACCEPTED_OUTSIDE_TRACKER_AND_GAUGE, 'value'))));
    }
}
