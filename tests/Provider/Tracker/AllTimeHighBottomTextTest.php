<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\AllTimeHighBottomText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllTimeHighBottomTextTest extends TestCase
{
    /**
     * The last column says whether the text is read at a glance: the two that are not overflow the
     * readable width by a single character, so the device scrolls them, a reading nuisance and
     * never a lost tracker.
     *
     * @return iterable<string, array{float, ?string, string, bool}>
     */
    public static function provideAllTimeHighs(): iterable
    {
        yield 'bitcoin in dollars' => [107662.0, 'usd', 'ATH 107662$', false];
        yield 'bitcoin in euros, no symbol' => [107662.0, 'eur', 'ATH 107662', true];
        yield 'ethereum in thousands' => [4049.0, 'eur', 'ATH 4049', true];
        yield 'three digit share' => [260.4, 'usd', 'ATH 260.40$', false];
        yield 'two digit share' => [45.34, 'usd', 'ATH 45.34$', true];
        yield 'ETF priced to the cent' => [6.048, 'eur', 'ATH 6.05', true];
        yield 'unknown currency, no symbol' => [107662.0, 'chf', 'ATH 107662', true];
        yield 'currency left out' => [107662.0, null, 'ATH 107662', true];
    }

    #[DataProvider('provideAllTimeHighs')]
    public function testThePriceIsRenderedTheWayTheDeviceRendersTheRowAbove(
        float $price,
        ?string $currency,
        string $expectedText,
        bool $isReadWithoutScrolling,
    ): void {
        self::assertSame($expectedText, AllTimeHighBottomText::composeFrom($price, $currency));

        if ($isReadWithoutScrolling) {
            self::assertLessThanOrEqual(AllTimeHighBottomText::CHARACTERS_READ_WITHOUT_SCROLLING, mb_strlen($expectedText), $expectedText);

            return;
        }

        self::assertGreaterThan(AllTimeHighBottomText::CHARACTERS_READ_WITHOUT_SCROLLING, mb_strlen($expectedText), $expectedText);
    }

    public function testAnAbsentOrNonPositivePriceComposesNoText(): void
    {
        self::assertNull(AllTimeHighBottomText::composeFrom(null, 'usd'));
        self::assertNull(AllTimeHighBottomText::composeFrom(0.0, 'usd'));
        self::assertNull(AllTimeHighBottomText::composeFrom(-1.0, 'usd'));
    }

    public function testAPriceTooLongToWriteInFullFallsBackOnTheCondensedForm(): void
    {
        self::assertSame('ATH 1000000000000000T$', AllTimeHighBottomText::composeFrom(1e27, 'usd'));
    }

    public function testAPriceNoFormCanWriteComposesNoTextRatherThanLosingTheTracker(): void
    {
        self::assertNull(AllTimeHighBottomText::composeFrom(1e300, 'usd'));
    }
}
