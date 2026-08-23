<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Projection;

use App\Simulator\Projection\ColorProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorProjectionTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function readableColorProvider(): iterable
    {
        yield 'RGB array' => [[255, 136, 0], '#FF8800'];
        yield 'lowercase hex string' => ['#ff8800', '#FF8800'];
        yield 'uppercase hex string' => ['#FF8800', '#FF8800'];
        yield 'packed integer' => [16746496, '#FF8800'];
        yield 'black as the lowest packed integer' => [0, '#000000'];
        yield 'white as the highest packed integer' => [16777215, '#FFFFFF'];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableColorProvider(): iterable
    {
        yield 'RGB array missing a component' => [[255, 136]];
        yield 'RGB array with a fourth component' => [[255, 136, 0, 0]];
        yield 'RGB array holding a numeric string' => [['255', 136, 0]];
        yield 'RGB array out of range' => [[300, 0, 0]];
        yield 'color name' => ['blue'];
        yield 'negative packed integer' => [-1];
        yield 'packed integer above white' => [16777216];
        yield 'nothing' => [null];
        yield 'boolean' => [true];
        yield 'object shaped color' => [['red' => 255, 'green' => 136, 'blue' => 0]];
    }

    #[DataProvider('readableColorProvider')]
    public function testAnAcceptedColorFormComesBackAsAHexCode(mixed $pushedColor, string $expectedHexCode): void
    {
        self::assertSame($expectedHexCode, ColorProjection::hexCodeOf($pushedColor));
    }

    #[DataProvider('unreadableColorProvider')]
    public function testAColorTheSpecDoesNotAcceptHasNoHexCode(mixed $pushedColor): void
    {
        self::assertNull(ColorProjection::hexCodeOf($pushedColor));
    }
}
