<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\TrackerSyncConfig;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Message\SyncTrackerMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackerSyncConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<TrackerSyncConfig>, string}>
     */
    public static function provideTrackerSyncGroups(): iterable
    {
        yield 'coingecko' => [CoinGeckoSyncConfig::class, 'coingecko'];
        yield 'twelvedata' => [TwelveDataSyncConfig::class, 'twelvedata'];
        yield 'boursorama' => [BoursoramaSyncConfig::class, 'boursorama'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validOptions(): array
    {
        return [
            'enabled' => false,
            'interval' => '15 minutes',
            'items' => [
                ['symbol' => 'BTC', 'currency' => 'eur', 'icon' => '54326', 'label' => 'Bitcoin', 'labelColor' => '#4caf50', 'bottomText' => 'MSCI World'],
                ['symbol' => 'ETH', 'currency' => 'eur'],
            ],
        ];
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testTheSyncTypeIsTheProviderName(string $syncGroupClass, string $expectedSyncType): void
    {
        self::assertSame($expectedSyncType, $syncGroupClass::syncType());
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAValidOptionMapIsHydrated(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::validOptions());

        self::assertFalse($trackerSync->enabled);
        self::assertSame('15 minutes', $trackerSync->interval->expression);
        self::assertCount(2, $trackerSync->items);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnItemKeepsItsOptionalDisplayFieldsWhenItHasThemAndIsNullOtherwise(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::validOptions());

        self::assertSame('BTC', $trackerSync->items[0]->symbol);
        self::assertSame('eur', $trackerSync->items[0]->currency);
        self::assertSame('54326', $trackerSync->items[0]->icon);
        self::assertSame('Bitcoin', $trackerSync->items[0]->label);
        self::assertSame('#4CAF50', $trackerSync->items[0]->labelColor?->hexCode);
        self::assertSame('MSCI World', $trackerSync->items[0]->bottomText);

        self::assertSame('ETH', $trackerSync->items[1]->symbol);
        self::assertNull($trackerSync->items[1]->icon);
        self::assertNull($trackerSync->items[1]->label);
        self::assertNull($trackerSync->items[1]->labelColor);
        self::assertNull($trackerSync->items[1]->bottomText);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testWithoutFreshnessKeysTheSilenceToleratedIsThreeIntervalsAndTheBehaviourIsLeftToTheFirmware(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::validOptions());

        self::assertSame(2700, $trackerSync->staleDeclaration->staleAfterInSeconds);
        self::assertNull($trackerSync->staleDeclaration->staleBehavior);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testWithoutAnActiveWindowTheGroupRunsAroundTheClock(string $syncGroupClass, string $expectedSyncType): void
    {
        self::assertNull($syncGroupClass::fromOptions(self::validOptions())->activeWindow);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAGroupDeclaringAnActiveWindowKeepsIt(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(array_merge(self::validOptions(), [
            'activeWindow' => ['days' => ['mon'], 'from' => '09:00', 'to' => '17:45', 'timezone' => 'Europe/Paris'],
        ]));

        self::assertNotNull($trackerSync->activeWindow);
        self::assertSame('mon 09:00-17:45 Europe/Paris', (string) $trackerSync->activeWindow);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnActiveWindowSpanningMidnightNamesTheOption(string $syncGroupClass, string $expectedSyncType): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage(\sprintf('syncs.%s.activeWindow.to', $expectedSyncType));

        $syncGroupClass::fromOptions(array_merge(self::validOptions(), [
            'activeWindow' => ['from' => '22:00', 'to' => '06:00', 'timezone' => 'Europe/Paris'],
        ]));
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testATrackerGroupAcceptsTheFourBehavioursItsLayoutDraws(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'dim']));

        self::assertSame(StaleBehavior::Dim, $trackerSync->staleDeclaration->staleBehavior);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testTheGroupIsTriggeredByATrackerSyncMessageCarryingItsType(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::validOptions());

        self::assertEquals(new SyncTrackerMessage($expectedSyncType), $trackerSync->syncMessage());
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnIntervalTheSchedulerCannotParseNamesTheOption(string $syncGroupClass, string $expectedSyncType): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage(\sprintf('syncs.%s.interval', $expectedSyncType));
        $syncGroupClass::fromOptions(array_merge(self::validOptions(), ['interval' => 'every fortnight']));
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnItemMissingAnOptionNamesItsIndex(string $syncGroupClass, string $expectedSyncType): void
    {
        $options = array_merge(self::validOptions(), [
            'items' => [
                ['symbol' => 'BTC', 'currency' => 'eur'],
                ['currency' => 'eur'],
            ],
        ]);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage(\sprintf('syncs.%s.items[1].symbol', $expectedSyncType));

        $syncGroupClass::fromOptions($options);
    }
}
