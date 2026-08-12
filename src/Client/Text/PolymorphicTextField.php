<?php

declare(strict_types=1);

namespace App\Client\Text;

use App\Client\Color;

/**
 * A text field in one of the forms the device reads: a plain string, or a list of colored segments
 * shown one after the other. The contract also accepts a lone text and its color as an object; that
 * form says nothing a one-segment list does not, so it is never emitted.
 */
final readonly class PolymorphicTextField
{
    /**
     * @param string|list<TextSegment> $value
     */
    private function __construct(
        private string|array $value,
    ) {
    }

    public static function fromPlainText(string $text): self
    {
        return new self($text);
    }

    public static function fromColoredText(string $text, Color $color): self
    {
        return self::fromSegments(TextSegment::create($text, $color));
    }

    public static function fromSegments(TextSegment ...$segments): self
    {
        if ([] === $segments) {
            throw new \InvalidArgumentException('A colored text field needs at least one segment.');
        }

        return new self(array_values($segments));
    }

    public function toPlainText(): string
    {
        if (\is_string($this->value)) {
            return $this->value;
        }

        $segmentTexts = array_map(
            static fn (TextSegment $segment): string => $segment->text,
            $this->value,
        );

        return implode('', $segmentTexts);
    }

    /**
     * The character budget is shared by every segment, so it is measured on the whole text. The
     * segment cap only binds the segmented form, the contract carrying it on that branch alone.
     */
    public function assertFitsWithin(string $fieldDescription, int $maximumCharacters, int $maximumSegments): void
    {
        $characterCount = mb_strlen($this->toPlainText());

        if ($characterCount > $maximumCharacters) {
            throw new \InvalidArgumentException(\sprintf('A %s holds at most %d characters, got %d.', $fieldDescription, $maximumCharacters, $characterCount));
        }

        if (\is_string($this->value)) {
            return;
        }

        $segmentCount = \count($this->value);

        if ($segmentCount > $maximumSegments) {
            throw new \InvalidArgumentException(\sprintf('A %s holds at most %d colored segments, got %d.', $fieldDescription, $maximumSegments, $segmentCount));
        }
    }

    /**
     * @return string|list<array{t: string, c: string}>
     */
    public function toPayloadValue(): string|array
    {
        if (\is_string($this->value)) {
            return $this->value;
        }

        return array_map(
            static fn (TextSegment $segment): array => $segment->toArray(),
            $this->value,
        );
    }
}
