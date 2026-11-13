<?php

declare(strict_types=1);

namespace App\Tests\Client\Watchlist;

use App\Client\Color;
use App\Client\StaleBehavior;
use App\Client\Watchlist\WatchlistPayload;
use App\Client\Watchlist\WatchlistRow;
use PHPUnit\Framework\TestCase;

final class WatchlistPayloadTest extends TestCase
{
    public function testTheBodyCarriesTheRowsInTheirOrderAndLeavesTheNameToTheQuery(): void
    {
        $watchlist = WatchlistPayload::create(
            name: 'watchlist',
            rows: [
                WatchlistRow::create('SpaceX', 184.2, 3.42, currency: 'USD', iconName: 'spacex', symbolColor: Color::fromHexCode('#FFFFFF')),
                WatchlistRow::create('Meta', 612.85, -1.08),
            ],
            staleAfterInSeconds: 2700,
            staleBehavior: StaleBehavior::Dim,
        );

        self::assertSame(
            [
                'rows' => [
                    ['symbol' => 'SpaceX', 'icon' => 'spacex', 'value' => 184.2, 'currency' => 'USD', 'change' => 3.42, 'symbolColor' => '#FFFFFF'],
                    ['symbol' => 'Meta', 'value' => 612.85, 'change' => -1.08],
                ],
                'staleAfter' => 2700,
                'staleBehavior' => 'dim',
            ],
            $watchlist->toArray(),
        );
    }

    public function testAnEmptyRowListIsSentSinceItClearsTheStoredRows(): void
    {
        self::assertSame(['rows' => []], WatchlistPayload::create('watchlist', [])->toArray());
    }

    public function testANameOverTheDeviceLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WatchlistPayload::create(str_repeat('a', WatchlistPayload::MAXIMUM_NAME_LENGTH + 1), []);
    }

    public function testMoreRowsThanTheCarouselHoldsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WatchlistPayload::create('watchlist', array_fill(0, WatchlistPayload::MAXIMUM_ROWS + 1, WatchlistRow::create('Meta', 612.85, -1.08)));
    }

    public function testASymbolOverTheDeviceLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WatchlistRow::create(str_repeat('a', WatchlistRow::MAXIMUM_SYMBOL_LENGTH + 1), 1.0, 0.0);
    }

    public function testACurrencyOverTheDeviceLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WatchlistRow::create('Meta', 1.0, 0.0, currency: str_repeat('a', WatchlistRow::MAXIMUM_CURRENCY_LENGTH + 1));
    }
}
