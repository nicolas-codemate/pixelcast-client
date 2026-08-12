<?php

declare(strict_types=1);

namespace App\Client\Text;

use App\Client\Color;

final readonly class TextSegment
{
    private function __construct(
        public string $text,
        public Color $color,
    ) {
    }

    public static function create(string $text, Color $color): self
    {
        if ('' === $text) {
            throw new \InvalidArgumentException('A text segment needs a non-empty text.');
        }

        return new self($text, $color);
    }

    /**
     * @return array{t: string, c: string}
     */
    public function toArray(): array
    {
        return [
            't' => $this->text,
            'c' => $this->color->hexCode,
        ];
    }
}
