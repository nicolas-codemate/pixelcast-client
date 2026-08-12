<?php

declare(strict_types=1);

namespace App\Client\Text;

use App\Client\Color;

/**
 * A text field in one of the two forms the device reads: a plain string, or a list of colored
 * segments shown one after the other.
 */
final readonly class PolymorphicTextField
{
    /**
     * @param list<TextSegment> $segments
     */
    private function __construct(
        private ?string $plainText,
        private array $segments,
    ) {
    }

    public static function fromPlainText(string $text): self
    {
        return new self($text, []);
    }

    public static function fromColoredText(string $text, Color $color): self
    {
        return new self(null, [TextSegment::create($text, $color)]);
    }

    public static function fromSegments(TextSegment ...$segments): self
    {
        $orderedSegments = array_values($segments);

        if ([] === $orderedSegments) {
            throw new \InvalidArgumentException('A colored text field needs at least one segment.');
        }

        return new self(null, $orderedSegments);
    }

    public function toPlainText(): string
    {
        if (null !== $this->plainText) {
            return $this->plainText;
        }

        $segmentTexts = array_map(
            static fn (TextSegment $segment): string => $segment->text,
            $this->segments,
        );

        return implode('', $segmentTexts);
    }

    public function segmentCount(): int
    {
        if (null !== $this->plainText) {
            return 1;
        }

        return \count($this->segments);
    }

    /**
     * The character budget is shared by every segment, so it is measured on the whole text.
     */
    public function assertFitsWithin(string $fieldDescription, int $maximumCharacters, int $maximumSegments): void
    {
        $characterCount = mb_strlen($this->toPlainText());

        if ($characterCount > $maximumCharacters) {
            throw new \InvalidArgumentException(\sprintf('A %s holds at most %d characters, got %d.', $fieldDescription, $maximumCharacters, $characterCount));
        }

        $segmentCount = $this->segmentCount();

        if ($segmentCount > $maximumSegments) {
            throw new \InvalidArgumentException(\sprintf('A %s holds at most %d colored segments, got %d.', $fieldDescription, $maximumSegments, $segmentCount));
        }
    }

    /**
     * @return string|list<array{t: string, c: string}>
     */
    public function toPayloadValue(): string|array
    {
        if (null !== $this->plainText) {
            return $this->plainText;
        }

        return array_map(
            static fn (TextSegment $segment): array => $segment->toArray(),
            $this->segments,
        );
    }
}
