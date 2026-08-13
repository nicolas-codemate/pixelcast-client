<?php

declare(strict_types=1);

namespace App\Client\CustomApp;

use App\Client\Color;
use App\Client\Text\PolymorphicTextField;

final readonly class Zone
{
    public const int MAXIMUM_TEXT_LENGTH = 31;
    public const int MAXIMUM_TEXT_SEGMENTS = 8;
    public const int MAXIMUM_LABEL_LENGTH = 31;
    public const int MAXIMUM_LABEL_SEGMENTS = 8;

    private function __construct(
        public ?PolymorphicTextField $text,
        public ?string $iconName,
        public ?PolymorphicTextField $label,
        public ?Color $color,
    ) {
    }

    public static function create(
        string|PolymorphicTextField|null $text = null,
        ?string $iconName = null,
        string|PolymorphicTextField|null $label = null,
        ?Color $color = null,
    ): self {
        $textField = \is_string($text) ? PolymorphicTextField::fromPlainText($text) : $text;
        $textField?->assertFitsWithin('custom app zone text', self::MAXIMUM_TEXT_LENGTH, self::MAXIMUM_TEXT_SEGMENTS);

        $labelField = \is_string($label) ? PolymorphicTextField::fromPlainText($label) : $label;
        $labelField?->assertFitsWithin('custom app zone label', self::MAXIMUM_LABEL_LENGTH, self::MAXIMUM_LABEL_SEGMENTS);

        return new self(
            text: $textField,
            iconName: $iconName,
            label: $labelField,
            color: $color,
        );
    }

    /**
     * @return array{text?: string|list<array{t: string, c: string}>, icon?: string, label?: string|list<array{t: string, c: string}>, color?: string}
     */
    public function toArray(): array
    {
        $payload = [];

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

        return $payload;
    }
}
