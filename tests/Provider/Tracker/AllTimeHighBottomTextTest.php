<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;
use App\Provider\Tracker\AllTimeHighBottomText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllTimeHighBottomTextTest extends TestCase
{
    /**
     * These two overflow the readable width by a single character, so the device scrolls
     * them: a reading nuisance, never a lost tracker.
     */
    private const array CASES_ACCEPTED_AS_SCROLLING = [
        'bitcoin in dollars',
        'three digit share',
    ];

    /**
     * @return iterable<string, array{float, ?string, string}>
     */
    public static function provideAllTimeHighs(): iterable
    {
        yield 'bitcoin in dollars' => [107662.0, 'usd', 'ATH 107662$'];
        yield 'bitcoin in euros, no symbol' => [107662.0, 'eur', 'ATH 107662'];
        yield 'ethereum in thousands' => [4049.0, 'eur', 'ATH 4049'];
        yield 'three digit share' => [260.4, 'usd', 'ATH 260.40$'];
        yield 'two digit share' => [45.34, 'usd', 'ATH 45.34$'];
        yield 'ETF priced to the cent' => [6.048, 'eur', 'ATH 6.05'];
        yield 'unknown currency, no symbol' => [107662.0, 'chf', 'ATH 107662'];
        yield 'currency left out' => [107662.0, null, 'ATH 107662'];
    }

    #[DataProvider('provideAllTimeHighs')]
    public function testThePriceIsRenderedTheWayTheDeviceRendersTheRowAbove(float $price, ?string $currency, string $expectedText): void
    {
        self::assertSame($expectedText, AllTimeHighBottomText::composeFrom($price, $currency));
    }

    public function testAnAbsentOrNonPositivePriceComposesNoText(): void
    {
        self::assertNull(AllTimeHighBottomText::composeFrom(null, 'usd'));
        self::assertNull(AllTimeHighBottomText::composeFrom(0.0, 'usd'));
        self::assertNull(AllTimeHighBottomText::composeFrom(-1.0, 'usd'));
    }

    public function testTheRealisticCasesAreReadWithoutScrolling(): void
    {
        foreach (self::provideAllTimeHighs() as $caseName => [, , $expectedText]) {
            if (\in_array($caseName, self::CASES_ACCEPTED_AS_SCROLLING, true)) {
                self::assertSame(AllTimeHighBottomText::CHARACTERS_READ_WITHOUT_SCROLLING + 1, mb_strlen($expectedText), $expectedText);

                continue;
            }

            self::assertLessThanOrEqual(AllTimeHighBottomText::CHARACTERS_READ_WITHOUT_SCROLLING, mb_strlen($expectedText), $expectedText);
        }
    }

    public function testEveryComposedTextIsAcceptedByTheTrackerPayloadContract(): void
    {
        $composedTexts = [AllTimeHighBottomText::composeFrom(1e27, 'usd')];
        foreach (self::provideAllTimeHighs() as [$price, $currency]) {
            $composedTexts[] = AllTimeHighBottomText::composeFrom($price, $currency);
        }

        foreach ($composedTexts as $composedText) {
            self::assertNotNull($composedText);
            self::assertLessThanOrEqual(TrackerPayload::MAXIMUM_BOTTOM_TEXT_LENGTH, mb_strlen($composedText), $composedText);
        }
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
