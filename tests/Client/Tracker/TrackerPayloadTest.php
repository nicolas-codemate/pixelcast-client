<?php

declare(strict_types=1);

namespace App\Tests\Client\Tracker;

use App\Client\Color;
use App\Client\StaleBehavior;
use App\Client\Tracker\TrackerPayload;
use PHPUnit\Framework\TestCase;

final class TrackerPayloadTest extends TestCase
{
    public function testToArrayOfAMinimalPayloadIsEmptyBecauseTheNameIsNotPartOfTheBody(): void
    {
        $payload = new TrackerPayload(name: 'BTC');

        self::assertSame([], $payload->toArray());
    }

    public function testToArrayEmitsEveryProvidedFieldUnderItsSpecKey(): void
    {
        $payload = new TrackerPayload(
            name: 'BTC',
            symbol: 'BTC',
            iconName: 'bitcoin',
            currency: 'USD',
            currentValue: 98452.30,
            changePercentage: 2.14,
            sparklinePoints: [92100.0, 89300.0, 93200.0],
            symbolColor: Color::fromHexCode('#FF8800'),
            sparklineColor: Color::fromHexCode('#00D4FF'),
            sparklinePeriod: '7d',
            bottomText: 'Vol 24h: 42B',
            displayDurationMilliseconds: 10000,
            staleAfterInSeconds: 2700,
            staleBehavior: StaleBehavior::Dim,
        );

        self::assertSame(
            [
                'symbol' => 'BTC',
                'icon' => 'bitcoin',
                'currency' => 'USD',
                'value' => 98452.30,
                'change' => 2.14,
                'sparkline' => [92100.0, 89300.0, 93200.0],
                'symbolColor' => '#FF8800',
                'sparklineColor' => '#00D4FF',
                'sparklinePeriod' => '7d',
                'bottomText' => 'Vol 24h: 42B',
                'duration' => 10000,
                'staleAfter' => 2700,
                'staleBehavior' => 'dim',
            ],
            $payload->toArray(),
        );
    }

    public function testToArrayCarriesAStaleAfterOfZeroBecauseItMeansTheAppNeverGoesStale(): void
    {
        $payload = new TrackerPayload(name: 'BTC', staleAfterInSeconds: 0);

        self::assertSame(['staleAfter' => 0], $payload->toArray());
    }

    public function testToArrayOmitsTheFreshnessKeysWhenTheGroupDeclaredNeither(): void
    {
        $payload = new TrackerPayload(name: 'BTC', symbol: 'BTC');

        self::assertArrayNotHasKey('staleAfter', $payload->toArray());
        self::assertArrayNotHasKey('staleBehavior', $payload->toArray());
    }

    public function testToArrayOmitsTheSparklineKeyWhenThereIsNoPoint(): void
    {
        $payload = new TrackerPayload(name: 'BTC', symbol: 'BTC', sparklinePoints: []);

        self::assertSame(['symbol' => 'BTC'], $payload->toArray());
    }

    public function testConstructorAcceptsOnePointPerChartColumn(): void
    {
        $payload = new TrackerPayload(name: 'BTC', sparklinePoints: self::buildSparklinePoints(63));

        self::assertCount(63, $payload->sparklinePoints);
    }

    public function testConstructorRejectsAPointBeyondTheLastChartColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 63 points, got 64');

        new TrackerPayload(name: 'BTC', sparklinePoints: self::buildSparklinePoints(64));
    }

    public function testConstructorRejectsAnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A tracker needs a non-empty name.');

        new TrackerPayload(name: '');
    }

    public function testConstructorRejectsAnOverlongSymbol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A tracker symbol holds at most 31 characters, got 32.');

        new TrackerPayload(name: 'BTC', symbol: str_repeat('a', 32));
    }

    public function testConstructorRejectsAnOverlongCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A tracker currency holds at most 7 characters, got 8.');

        new TrackerPayload(name: 'BTC', currency: 'USDOLLAR');
    }

    public function testConstructorRejectsAnOverlongBottomText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A tracker bottom text holds at most 31 characters, got 32.');

        new TrackerPayload(name: 'BTC', bottomText: str_repeat('a', 32));
    }

    public function testConstructorRejectsASparklinePeriodLongerThanFourCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sparkline period holds at most 4 characters, got 5');

        new TrackerPayload(name: 'BTC', sparklinePeriod: '1week');
    }

    /**
     * @return list<float>
     */
    private static function buildSparklinePoints(int $pointCount): array
    {
        return array_fill(0, $pointCount, 1.5);
    }
}
