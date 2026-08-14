<?php

declare(strict_types=1);

namespace App\Tests\Client\Settings;

use App\Client\Settings\BrightnessLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrightnessLevelTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function provideBoundaryLevelCases(): iterable
    {
        yield 'lower bound' => [0];
        yield 'upper bound' => [255];
    }

    #[DataProvider('provideBoundaryLevelCases')]
    public function testCreateAcceptsTheLevelBounds(int $level): void
    {
        $brightness = BrightnessLevel::create($level);

        self::assertSame($level, $brightness->level);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideOutOfRangeLevelCases(): iterable
    {
        yield 'below zero' => [-1];
        yield 'above the maximum' => [256];
    }

    #[DataProvider('provideOutOfRangeLevelCases')]
    public function testCreateRejectsAnOutOfRangeLevel(int $level): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be between 0 and 255');

        BrightnessLevel::create($level);
    }
}
