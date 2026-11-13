<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\WatchlistQuoteSource;
use App\Config\Sync\WatchlistSyncConfig;
use App\Message\SyncWatchlistMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WatchlistSyncConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validOptions(): array
    {
        return [
            'enabled' => true,
            'interval' => '15 minutes',
            'items' => [
                ['source' => 'boursorama', 'symbol' => 'SPCX', 'currency' => 'usd', 'label' => 'SpaceX', 'labelColor' => '#ffffff', 'icon' => 'spacex', 'lametricId' => 7674],
                ['source' => 'boursorama', 'symbol' => 'META', 'currency' => 'usd'],
            ],
        ];
    }

    public function testTheSyncTypeIsTheKeyUsedInTheConfigurationFile(): void
    {
        self::assertSame('watchlist', WatchlistSyncConfig::syncType());
    }

    public function testAValidOptionMapIsHydratedWithItsQuotesInTheirOrder(): void
    {
        $watchlistSync = WatchlistSyncConfig::fromOptions(self::validOptions(), null);

        self::assertTrue($watchlistSync->enabled);
        self::assertSame('15 minutes', $watchlistSync->interval->expression);
        self::assertSame(['SPCX', 'META'], array_column($watchlistSync->items, 'symbol'));
        self::assertSame([WatchlistQuoteSource::Boursorama, WatchlistQuoteSource::Boursorama], array_column($watchlistSync->items, 'source'));
    }

    public function testTheOptionalKeysOfAQuoteAreReadOrLeftOut(): void
    {
        [$described, $bare] = WatchlistSyncConfig::fromOptions(self::validOptions(), null)->items;

        self::assertSame('SpaceX', $described->label);
        self::assertSame('#FFFFFF', $described->labelColor?->hexCode);
        self::assertSame('spacex', $described->icon);
        self::assertSame(7674, $described->lametricId);
        self::assertNull($bare->label);
        self::assertNull($bare->labelColor);
        self::assertNull($bare->icon);
        self::assertNull($bare->lametricId);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideQuoteCountsTheAppCannotHold(): iterable
    {
        yield 'none' => [0];
        yield 'ten' => [10];
    }

    #[DataProvider('provideQuoteCountsTheAppCannotHold')]
    public function testAQuoteCountTheAppCannotHoldNamesItsFullPath(int $quoteCount): void
    {
        $items = array_fill(0, $quoteCount, ['source' => 'boursorama', 'symbol' => 'META', 'currency' => 'usd']);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.watchlist.items');

        WatchlistSyncConfig::fromOptions(array_merge(self::validOptions(), ['items' => $items]), null);
    }

    public function testAnUnknownSourceNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.watchlist.items[0].source');

        WatchlistSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'items' => [['source' => 'tradingview', 'symbol' => 'META', 'currency' => 'usd']],
        ]), null);
    }

    public function testEveryBehaviourIsAcceptedSinceTheWatchlistLayoutDrawsTheBadge(): void
    {
        foreach (StaleBehavior::cases() as $staleBehavior) {
            $watchlistSync = WatchlistSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => $staleBehavior->value]), null);

            self::assertSame($staleBehavior, $watchlistSync->staleDeclaration->staleBehavior);
        }
    }

    public function testWithoutFreshnessKeysTheSilenceToleratedIsThreeIntervals(): void
    {
        self::assertSame(2700, WatchlistSyncConfig::fromOptions(self::validOptions(), null)->staleDeclaration->staleAfterInSeconds);
    }

    public function testTheGroupIsTriggeredByAWatchlistSyncMessage(): void
    {
        self::assertInstanceOf(SyncWatchlistMessage::class, WatchlistSyncConfig::fromOptions(self::validOptions(), null)->syncMessage());
    }
}
