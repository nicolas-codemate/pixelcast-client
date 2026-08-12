<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\AllTimeHighBottomText;
use App\Tracker\AllTimeHigh;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllTimeHighBottomTextTest extends TestCase
{
    private const string SYNC_TYPE = 'coingecko';
    private const string SYMBOL = 'bitcoin';

    /**
     * @return iterable<string, array{float, string, ?string, string}>
     */
    public static function provideAllTimeHighs(): iterable
    {
        yield 'bitcoin in dollars, condensed on the thousand' => [107662.0, 'usd', '2025-10-06', 'ATH 107.7K$ 10/2025'];
        yield 'bitcoin in euros, no symbol' => [107662.0, 'eur', '2025-10-06', 'ATH 107.7K 10/2025'];
        yield 'ethereum in thousands' => [4049.0, 'eur', '2025-08-24', 'ATH 4.0K 08/2025'];
        yield 'three digit share, written in full' => [260.4, 'usd', '2024-03-12', 'ATH 260.40$ 03/2024'];
        yield 'two digit share' => [45.34, 'usd', '2024-03-12', 'ATH 45.34$ 03/2024'];
        yield 'ETF priced to the cent' => [6.048, 'eur', '2024-03-12', 'ATH 6.05 03/2024'];
        yield 'unknown currency, no symbol' => [107662.0, 'chf', '2025-10-06', 'ATH 107.7K 10/2025'];
        yield 'a high the source never dated' => [107662.0, 'usd', null, 'ATH 107.7K$'];
    }

    #[DataProvider('provideAllTimeHighs')]
    public function testTheHighIsWrittenWithTheMonthAndYearItWasReached(
        float $price,
        string $currency,
        ?string $reachedOn,
        string $expectedText,
    ): void {
        self::assertSame($expectedText, AllTimeHighBottomText::composeFrom(self::allTimeHighOf($price, $currency, $reachedOn)));
    }

    public function testDatingTheHighCostsTheRowTheWidthItIsReadAtAGlanceIn(): void
    {
        $undatedText = AllTimeHighBottomText::composeFrom(self::allTimeHighOf(107662.0, 'eur', null));
        $datedText = AllTimeHighBottomText::composeFrom(self::allTimeHighOf(107662.0, 'eur', '2025-10-06'));

        self::assertNotNull($undatedText);
        self::assertNotNull($datedText);
        self::assertLessThanOrEqual(AllTimeHighBottomText::CHARACTERS_READ_WITHOUT_SCROLLING, mb_strlen($undatedText));
        self::assertGreaterThan(AllTimeHighBottomText::CHARACTERS_READ_WITHOUT_SCROLLING, mb_strlen($datedText));
    }

    public function testAnAbsentOrNonPositivePriceComposesNoText(): void
    {
        self::assertNull(AllTimeHighBottomText::composeFrom(null));
        self::assertNull(AllTimeHighBottomText::composeFrom(self::allTimeHighOf(0.0, 'usd', '2024-03-12')));
        self::assertNull(AllTimeHighBottomText::composeFrom(self::allTimeHighOf(-1.0, 'usd', '2024-03-12')));
    }

    public function testAHighTooWideToCarryItsDateKeepsThePriceAndDropsTheDate(): void
    {
        self::assertSame('ATH 1000000000000000.0T$', AllTimeHighBottomText::composeFrom(self::allTimeHighOf(1e27, 'usd', '2024-03-12')));
    }

    public function testAPriceNoFormCanWriteComposesNoTextRatherThanLosingTheTracker(): void
    {
        self::assertNull(AllTimeHighBottomText::composeFrom(self::allTimeHighOf(1e300, 'usd', '2024-03-12')));
    }

    private static function allTimeHighOf(float $price, string $currency, ?string $reachedOn): AllTimeHigh
    {
        return new AllTimeHigh(
            self::SYNC_TYPE,
            self::SYMBOL,
            $currency,
            $price,
            null === $reachedOn ? null : new \DateTimeImmutable($reachedOn.' 12:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-08-12 09:00:00', new \DateTimeZone('UTC')),
        );
    }
}
