<?php

declare(strict_types=1);

namespace App\Tests\Client\Text;

use App\Client\Color;
use App\Client\Text\TextSegment;
use PHPUnit\Framework\TestCase;

final class TextSegmentTest extends TestCase
{
    public function testASegmentSerializesToItsTextAndColorPair(): void
    {
        $segment = TextSegment::create('Claude', Color::fromHexCode('#D97757'));

        self::assertSame(['t' => 'Claude', 'c' => '#D97757'], $segment->toArray());
    }

    public function testTheSerializedColorIsNormalizedToUppercase(): void
    {
        $segment = TextSegment::create(' reset', Color::fromHexCode('d97757'));

        self::assertSame(['t' => ' reset', 'c' => '#D97757'], $segment->toArray());
    }

    public function testASegmentKeepsItsTextAndColorReadable(): void
    {
        $color = Color::fromHexCode('#FFFFFF');

        $segment = TextSegment::create('fable', $color);

        self::assertSame('fable', $segment->text);
        self::assertSame($color, $segment->color);
    }

    public function testAnEmptyTextIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A text segment needs a non-empty text.');

        TextSegment::create('', Color::fromHexCode('#FFFFFF'));
    }
}
