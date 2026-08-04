<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\TradedVolumeBottomText;
use PHPUnit\Framework\TestCase;

final class TradedVolumeBottomTextTest extends TestCase
{
    public function testEachMagnitudeCarriesItsOwnSuffix(): void
    {
        self::assertSame('Volume : 25T', TradedVolumeBottomText::composeFrom(25_100_904_262_000.0));
        self::assertSame('Volume : 25B', TradedVolumeBottomText::composeFrom(25_100_904_262.0));
        self::assertSame('Volume : 25M', TradedVolumeBottomText::composeFrom(25_100_904.0));
        self::assertSame('Volume : 25K', TradedVolumeBottomText::composeFrom(25_100.0));
        self::assertSame('Volume : 251', TradedVolumeBottomText::composeFrom(251.0));
    }

    public function testADecimalIsKeptBelowTenUnits(): void
    {
        self::assertSame('Volume : 9.4B', TradedVolumeBottomText::composeFrom(9_420_000_000.0));
        self::assertSame('Volume : 1.5K', TradedVolumeBottomText::composeFrom(1_500.0));
    }

    public function testTenUnitsAndAboveDropTheDecimal(): void
    {
        self::assertSame('Volume : 10B', TradedVolumeBottomText::composeFrom(10_000_000_000.0));
        self::assertSame('Volume : 999B', TradedVolumeBottomText::composeFrom(999_400_000_000.0));
    }

    public function testAKnownCurrencyIsAppendedAsItsSymbol(): void
    {
        self::assertSame('Volume : 26B$', TradedVolumeBottomText::composeFrom(26_000_000_000.0, 'usd'));
        self::assertSame('Volume : 26B$', TradedVolumeBottomText::composeFrom(26_000_000_000.0, 'USD'));
    }

    public function testACurrencyWithoutAnAsciiSymbolIsLeftOut(): void
    {
        self::assertSame('Volume : 26B', TradedVolumeBottomText::composeFrom(26_000_000_000.0, 'eur'));
        self::assertSame('Volume : 26B', TradedVolumeBottomText::composeFrom(26_000_000_000.0, null));
    }

    public function testAMissingOrNonPositiveVolumeProducesNoBottomText(): void
    {
        self::assertNull(TradedVolumeBottomText::composeFrom(null, 'usd'));
        self::assertNull(TradedVolumeBottomText::composeFrom(0.0, 'usd'));
        self::assertNull(TradedVolumeBottomText::composeFrom(-1.0, 'usd'));
    }
}
