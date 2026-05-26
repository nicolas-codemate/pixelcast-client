<?php

declare(strict_types=1);

namespace App\Tests\Tui\Style;

use App\Tui\Style\Palette;
use PHPUnit\Framework\TestCase;

final class PaletteTest extends TestCase
{
    public function testDefaultTokensMatchSpec(): void
    {
        $palette = new Palette();

        self::assertSame('cyan', $palette->headerText);
        self::assertSame('yellow', $palette->devChipBackground);
        self::assertSame('black', $palette->devChipForeground);
        self::assertSame('red', $palette->prodChipBackground);
        self::assertSame('white', $palette->prodChipForeground);
        self::assertSame('gray', $palette->borderDim);
        self::assertSame('green', $palette->borderAccent);
        self::assertSame('gray', $palette->dimText);
        self::assertSame('bright_white', $palette->accentText);
    }

    public function testNamedParameterOverrideSticks(): void
    {
        $palette = new Palette(headerText: 'magenta');

        self::assertSame('magenta', $palette->headerText);
        self::assertSame('yellow', $palette->devChipBackground);
    }
}
