<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\BottomLine;
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
    public function testAnItemWithoutABottomLineKeyCarriesNoBottomLine(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::validOptions());

        self::assertNull($trackerSync->items[0]->bottomLine);
        self::assertNull($trackerSync->items[1]->bottomLine);
    }

    /**
     * @return iterable<string, array{class-string<TrackerSyncConfig>, BottomLine}>
     */
    public static function provideAcceptedBottomLines(): iterable
    {
        foreach (self::provideTrackerSyncGroups() as [$syncGroupClass, $syncType]) {
            foreach ($syncGroupClass::acceptedBottomLines() as $bottomLine) {
                yield $syncType.' '.$bottomLine->value => [$syncGroupClass, $bottomLine];
            }
        }
    }

    /**
     * @return iterable<string, array{class-string<TrackerSyncConfig>, string, BottomLine}>
     */
    public static function provideRefusedBottomLines(): iterable
    {
        foreach (self::provideTrackerSyncGroups() as [$syncGroupClass, $syncType]) {
            foreach (BottomLine::cases() as $bottomLine) {
                if (!\in_array($bottomLine, $syncGroupClass::acceptedBottomLines(), true)) {
                    yield $syncType.' '.$bottomLine->value => [$syncGroupClass, $syncType, $bottomLine];
                }
            }
        }
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideAcceptedBottomLines')]
    public function testAGroupReadsEveryBottomLineItAccepts(string $syncGroupClass, BottomLine $bottomLine): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::optionsWithBottomLine($bottomLine->value));

        self::assertSame($bottomLine, $trackerSync->items[0]->bottomLine);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideRefusedBottomLines')]
    public function testAGroupNamesTheOptionOfEveryBottomLineItRefuses(string $syncGroupClass, string $expectedSyncType, BottomLine $bottomLine): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage(\sprintf('syncs.%s.items[0].bottomLine', $expectedSyncType));

        $syncGroupClass::fromOptions(self::optionsWithBottomLine($bottomLine->value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function optionsWithBottomLine(string $bottomLine): array
    {
        return array_merge(self::validOptions(), [
            'items' => [['symbol' => 'BTC', 'currency' => 'eur', 'bottomLine' => $bottomLine]],
        ]);
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

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnItemWindowSpanningMidnightNamesTheOptionOfThatItem(string $syncGroupClass, string $expectedSyncType): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage(\sprintf('syncs.%s.items[0].activeWindow.to', $expectedSyncType));

        $syncGroupClass::fromOptions(array_merge(self::validOptions(), [
            'items' => [
                ['symbol' => 'BTC', 'currency' => 'eur', 'activeWindow' => ['from' => '22:00', 'to' => '06:00', 'timezone' => 'Europe/Paris']],
            ],
        ]));
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnItemWithoutFreshnessKeysFollowsTheDeclarationOfItsGroup(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(array_merge(self::validOptions(), [
            'staleAfter' => 1200,
            'staleBehavior' => 'hide',
        ]));

        self::assertSame(1200, $trackerSync->items[0]->staleDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $trackerSync->items[0]->staleDeclaration->staleBehavior);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnItemDeclaringItsOwnSilenceKeepsTheBehaviourOfItsGroup(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(array_merge(self::validOptions(), [
            'staleAfter' => 1200,
            'staleBehavior' => 'hide',
            'items' => [['symbol' => 'BTC', 'currency' => 'eur', 'staleAfter' => 0]],
        ]));

        self::assertSame(0, $trackerSync->items[0]->staleDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $trackerSync->items[0]->staleDeclaration->staleBehavior);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAnItemDeclaringItsOwnBehaviourKeepsTheSilenceOfItsGroup(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(array_merge(self::validOptions(), [
            'staleAfter' => 1200,
            'staleBehavior' => 'hide',
            'items' => [['symbol' => 'BTC', 'currency' => 'eur', 'staleBehavior' => 'badge']],
        ]));

        self::assertSame(1200, $trackerSync->items[0]->staleDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Badge, $trackerSync->items[0]->staleDeclaration->staleBehavior);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testOnlyTheItemsWhoseOwnWindowIsOpenAreFetched(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::twoMarketsOptions());

        $itemsAtTheEuropeanOpening = $trackerSync->itemsToFetchAt(self::parisInstant('2026-08-03 10:00:00'));
        self::assertCount(1, $itemsAtTheEuropeanOpening);
        self::assertSame('EURONEXT', $itemsAtTheEuropeanOpening[0]->symbol);

        self::assertCount(2, $trackerSync->itemsToFetchAt(self::parisInstant('2026-08-03 16:00:00')));
        self::assertSame([], $trackerSync->itemsToFetchAt(self::parisInstant('2026-08-03 08:30:00')));
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testWithoutAnInstantToJudgeTheWindowsEveryItemIsFetched(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(self::twoMarketsOptions());

        self::assertSame($trackerSync->items, $trackerSync->itemsToFetchAt(null));
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAGroupIsDispatchableFromTheLaterOfItsOwnOpeningAndTheEarliestItemStillOpen(string $syncGroupClass, string $expectedSyncType): void
    {
        $activity = $syncGroupClass::fromOptions(self::twoMarketsOptions())->activityAt(self::parisInstant('2026-08-03 16:00:00'));

        self::assertTrue($activity->isActive);
        self::assertSame(25200, $activity->secondsSinceBecameActive);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testAGroupWhoseItemsAreAllClosedIsNotDispatchableEvenInsideItsOwnWindow(string $syncGroupClass, string $expectedSyncType): void
    {
        $activity = $syncGroupClass::fromOptions(self::twoMarketsOptions())->activityAt(self::parisInstant('2026-08-03 08:30:00'));

        self::assertFalse($activity->isActive);
        self::assertNull($activity->secondsSinceBecameActive);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testWithoutAGroupWindowTheItemWindowsAloneSayWhenTheGroupBecameDispatchable(string $syncGroupClass, string $expectedSyncType): void
    {
        $options = self::twoMarketsOptions();
        unset($options['activeWindow']);

        $activity = $syncGroupClass::fromOptions($options)->activityAt(self::parisInstant('2026-08-03 16:00:00'));

        self::assertTrue($activity->isActive);
        self::assertSame(25200, $activity->secondsSinceBecameActive);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testWithoutAnyWindowAtAllTheGroupHasAlwaysBeenDispatchable(string $syncGroupClass, string $expectedSyncType): void
    {
        $activity = $syncGroupClass::fromOptions(self::validOptions())->activityAt(self::parisInstant('2026-08-08 03:00:00'));

        self::assertTrue($activity->isActive);
        self::assertNull($activity->secondsSinceBecameActive);
    }

    /**
     * @param class-string<TrackerSyncConfig> $syncGroupClass
     */
    #[DataProvider('provideTrackerSyncGroups')]
    public function testItemsWithoutAWindowFollowTheGroupWindow(string $syncGroupClass, string $expectedSyncType): void
    {
        $trackerSync = $syncGroupClass::fromOptions(array_merge(self::validOptions(), [
            'activeWindow' => self::windowOptions('08:00', '18:00'),
        ]));

        self::assertSame(28800, $trackerSync->activityAt(self::parisInstant('2026-08-03 16:00:00'))->secondsSinceBecameActive);
        self::assertFalse($trackerSync->activityAt(self::parisInstant('2026-08-03 19:00:00'))->isActive);
    }

    /**
     * The two markets of the brief: a Euronext ETF and a US-listed one, under a group window
     * covering both.
     *
     * @return array<string, mixed>
     */
    private static function twoMarketsOptions(): array
    {
        return array_merge(self::validOptions(), [
            'activeWindow' => self::windowOptions('08:00', '18:00'),
            'items' => [
                ['symbol' => 'EURONEXT', 'currency' => 'eur', 'activeWindow' => self::windowOptions('09:00', '17:30')],
                ['symbol' => 'NASDAQ', 'currency' => 'usd', 'activeWindow' => self::windowOptions('15:30', '22:00')],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function windowOptions(string $from, string $to): array
    {
        return ['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'from' => $from, 'to' => $to, 'timezone' => 'Europe/Paris'];
    }

    private static function parisInstant(string $instant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instant, new \DateTimeZone('Europe/Paris'));
    }
}
