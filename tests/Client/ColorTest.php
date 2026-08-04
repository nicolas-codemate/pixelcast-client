<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedHexCodeCases(): iterable
    {
        yield 'lowercase with leading hash' => ['#ff8800'];
        yield 'uppercase without leading hash' => ['FF8800'];
        yield 'lowercase without leading hash' => ['ff8800'];
    }

    #[DataProvider('provideAcceptedHexCodeCases')]
    public function testFromHexCodeNormalisesToAnUppercaseHexCode(string $hexCode): void
    {
        self::assertSame('#FF8800', Color::fromHexCode($hexCode)->hexCode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedHexCodeCases(): iterable
    {
        yield 'three digits' => ['#FFF'];
        yield 'non hexadecimal digits' => ['#GG8800'];
        yield 'empty string' => [''];
    }

    #[DataProvider('provideRejectedHexCodeCases')]
    public function testFromHexCodeRejectsAMalformedHexCode(string $hexCode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A color hex code must hold 6 hexadecimal digits');

        Color::fromHexCode($hexCode);
    }

    public function testFromRgbComponentsFormatsEachComponentOnTwoDigits(): void
    {
        self::assertSame('#FF8800', Color::fromRgbComponents(255, 136, 0)->hexCode);
    }

    public function testFromRgbComponentsAcceptsTheLowerBound(): void
    {
        self::assertSame('#000000', Color::fromRgbComponents(0, 0, 0)->hexCode);
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function provideOutOfRangeComponentCases(): iterable
    {
        yield 'red below zero' => [-1, 0, 0, 'red'];
        yield 'green above 255' => [0, 256, 0, 'green'];
        yield 'blue below zero' => [0, 0, -1, 'blue'];
    }

    #[DataProvider('provideOutOfRangeComponentCases')]
    public function testFromRgbComponentsRejectsAnOutOfRangeComponent(int $red, int $green, int $blue, string $expectedComponentName): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The %s component must be between 0 and 255', $expectedComponentName));

        Color::fromRgbComponents($red, $green, $blue);
    }

    public function testFromPackedRgbFormatsTheWholeValueOnSixDigits(): void
    {
        self::assertSame('#FF8800', Color::fromPackedRgb(16746496)->hexCode);
    }

    public function testFromPackedRgbAcceptsTheLowerBound(): void
    {
        self::assertSame('#000000', Color::fromPackedRgb(0)->hexCode);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideOutOfRangePackedValueCases(): iterable
    {
        yield 'below zero' => [-1];
        yield 'above the 24 bit range' => [16777216];
    }

    #[DataProvider('provideOutOfRangePackedValueCases')]
    public function testFromPackedRgbRejectsAnOutOfRangeValue(int $packedRgb): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A packed color must be between 0 and 16777215');

        Color::fromPackedRgb($packedRgb);
    }
}
