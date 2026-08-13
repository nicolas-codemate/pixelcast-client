<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sleep\SleepDisplayMode;
use App\Config\Sync\ActiveWindowDay;
use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Config\Sync\WeatherSyncConfig;
use App\Config\SyncsConfigLoader;
use App\Config\WeatherLocale;
use App\Config\WeatherUnits;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncsConfigLoaderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';

    public function testAValidFileIsHydratedIntoOneGroupPerSyncType(): void
    {
        $config = self::loaderFor('syncs-valid.yaml')->load();

        $weatherSync = $config->syncGroupOfType(WeatherSyncConfig::class);
        self::assertTrue($weatherSync->enabled);
        self::assertSame('30 minutes', $weatherSync->interval->expression);
        self::assertSame(48.8566, $weatherSync->latitude);
        self::assertSame(2.3522, $weatherSync->longitude);
        self::assertSame(WeatherUnits::Metric, $weatherSync->units);
        self::assertSame(WeatherLocale::French, $weatherSync->locale);

        $coinGeckoSync = $config->syncGroupOfType(CoinGeckoSyncConfig::class);
        self::assertFalse($coinGeckoSync->enabled);
        self::assertCount(1, $coinGeckoSync->items);
        self::assertSame('bitcoin', $coinGeckoSync->items[0]->symbol);
        self::assertSame('eur', $coinGeckoSync->items[0]->currency);
        self::assertSame('1234', $coinGeckoSync->items[0]->icon);

        $twelveDataSync = $config->syncGroupOfType(TwelveDataSyncConfig::class);
        self::assertFalse($twelveDataSync->enabled);
        self::assertNull($twelveDataSync->items[0]->icon);
    }

    public function testAGroupWithoutFreshnessKeysDerivesItsSilenceFromItsInterval(): void
    {
        $config = self::loaderFor('syncs-valid.yaml')->load();

        $weatherSync = $config->syncGroupOfType(WeatherSyncConfig::class);
        self::assertSame(5400, $weatherSync->staleDeclaration->staleAfterInSeconds);
        self::assertNull($weatherSync->staleDeclaration->staleBehavior);
    }

    public function testAGroupDeclaringBothFreshnessKeysKeepsThem(): void
    {
        $config = self::loaderFor('syncs-stale-overrides.yaml')->load();

        $weatherSync = $config->syncGroupOfType(WeatherSyncConfig::class);
        self::assertSame(7200, $weatherSync->staleDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::None, $weatherSync->staleDeclaration->staleBehavior);

        $boursoramaSync = $config->syncGroupOfType(BoursoramaSyncConfig::class);
        self::assertSame(0, $boursoramaSync->staleDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $boursoramaSync->staleDeclaration->staleBehavior);
    }

    public function testAGroupDeclaringAnActiveWindowKeepsItsDaysItsBoundsAndItsTimezone(): void
    {
        $config = self::loaderFor('syncs-active-window.yaml')->load();

        $boursoramaSync = $config->syncGroupOfType(BoursoramaSyncConfig::class);
        self::assertNotNull($boursoramaSync->activeWindow);
        self::assertSame('mon,tue,wed,thu,fri 09:00-17:45 Europe/Paris', (string) $boursoramaSync->activeWindow);

        self::assertNull($config->syncGroupOfType(WeatherSyncConfig::class)->activeWindow);
    }

    public function testAnItemDeclaringItsOwnActiveWindowKeepsItNextToTheGroupWindow(): void
    {
        $config = self::loaderFor('syncs-item-active-window.yaml')->load();

        $boursoramaSync = $config->syncGroupOfType(BoursoramaSyncConfig::class);
        self::assertSame('mon,tue,wed,thu,fri 08:00-22:00 Europe/Paris', (string) $boursoramaSync->activeWindow);
        self::assertSame('mon,tue,wed,thu,fri 09:00-17:30 Europe/Paris', (string) $boursoramaSync->items[0]->activeWindow);
        self::assertSame('mon,tue,wed,thu,fri 15:30-22:00 Europe/Paris', (string) $boursoramaSync->items[1]->activeWindow);
    }

    public function testAFileDeclaringASleepScheduleKeepsItsWindowItsDaysAndItsDisplayMode(): void
    {
        $config = self::loaderFor('syncs-with-sleep.yaml')->load();

        self::assertNotNull($config->deviceSleep);
        self::assertTrue($config->deviceSleep->enabled);
        self::assertSame(SleepDisplayMode::Black, $config->deviceSleep->displayMode);
        self::assertSame(ActiveWindowDay::cases(), $config->deviceSleep->days);
        self::assertSame(['00:00-07:00'], array_map(strval(...), $config->deviceSleep->windows));
        self::assertSame('Europe/Paris', $config->deviceSleep->timezone->getName());
        self::assertSame('mon,tue,wed,thu,fri,sat,sun 00:00-07:00 Europe/Paris', (string) $config->sleepSchedule());
    }

    public function testAFileWithoutASleepSectionCarriesNoSchedule(): void
    {
        $config = self::loaderFor('syncs-valid.yaml')->load();

        self::assertNull($config->deviceSleep);
        self::assertNull($config->sleepSchedule());
    }

    public function testADisabledSleepSectionCarriesNoSchedule(): void
    {
        $config = self::loaderFor('syncs-sleep-disabled.yaml')->load();

        self::assertNotNull($config->deviceSleep);
        self::assertNull($config->sleepSchedule());
    }

    public function testASleepWindowIsAllowedToRunPastMidnight(): void
    {
        $config = self::loaderFor('syncs-sleep-crossing-midnight.yaml')->load();

        self::assertNotNull($config->deviceSleep);
        self::assertSame(SleepDisplayMode::Clock, $config->deviceSleep->displayMode);
        self::assertSame([ActiveWindowDay::Friday, ActiveWindowDay::Saturday], $config->deviceSleep->days);
        self::assertSame(['22:00-07:00'], array_map(strval(...), $config->deviceSleep->windows));
    }

    public function testOnlyTheEnabledGroupsAreKept(): void
    {
        $config = self::loaderFor('syncs-valid.yaml')->load();

        self::assertSame(['weather'], array_keys($config->enabledSyncGroups()));
    }

    public function testTheTrackerGroupsAreListedWhateverTheirEnabledFlag(): void
    {
        $config = self::loaderFor('syncs-valid.yaml')->load();

        self::assertSame(['coingecko', 'twelvedata'], array_keys($config->trackerSyncGroups()));
    }

    public function testAFileWhereEveryGroupIsDisabledEnablesNothing(): void
    {
        $config = self::loaderFor('syncs-all-disabled.yaml')->load();

        self::assertSame([], $config->enabledSyncGroups());
    }

    public function testAskingForAGroupAbsentFromTheFileNamesItsPath(): void
    {
        $config = self::loaderFor('syncs-all-disabled.yaml')->load();

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.twelvedata');

        $config->syncGroupOfType(TwelveDataSyncConfig::class);
    }

    public function testTheFileIsReadOnlyOnce(): void
    {
        $loader = self::loaderFor('syncs-valid.yaml');

        self::assertSame($loader->load(), $loader->load());
    }

    public function testAMissingFileIsReported(): void
    {
        $loader = self::loaderFor('does-not-exist.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('not found');

        $loader->load();
    }

    public function testAMissingSchemaIsReported(): void
    {
        $loader = new SyncsConfigLoader(self::FIXTURES_DIR.'/syncs-valid.yaml', self::FIXTURES_DIR.'/does-not-exist.json');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('Failed to read the PixelCast config schema');

        $loader->load();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRejectedFileCases(): iterable
    {
        yield 'broken YAML' => ['syncs-invalid-syntax.yaml', 'Failed to parse PixelCast config'];
        yield 'group the schema does not declare' => ['syncs-unknown-group.yaml', 'nope'];
        yield 'tracker item without a symbol' => ['syncs-incomplete-item.yaml', 'syncs.coingecko.items[0].symbol'];
        yield 'interval the scheduler cannot parse' => ['syncs-bad-interval.yaml', 'syncs.weather.interval'];
        yield 'API key written in the file' => ['syncs-secret-in-file.yaml', 'api_key'];
        yield 'dim declared on the weather group' => ['syncs-weather-dim-behavior.yaml', 'syncs.weather.staleBehavior'];
        yield 'window spanning midnight' => ['syncs-window-crossing-midnight.yaml', 'syncs.boursorama.activeWindow.to'];
        yield 'window declaring a day that does not exist' => ['syncs-window-bad-day.yaml', 'syncs.boursorama.activeWindow.days'];
        yield 'window declaring an unknown timezone' => ['syncs-window-bad-timezone.yaml', 'syncs.boursorama.activeWindow.timezone'];
        yield 'item window spanning midnight' => ['syncs-item-window-crossing-midnight.yaml', 'syncs.boursorama.items[0].activeWindow.to'];
        yield 'bottom line the schema does not declare' => ['syncs-tracker-unknown-bottom-line.yaml', 'syncs.coingecko.items[0].bottomLine'];
        yield 'bottom line the group cannot serve' => ['syncs-tracker-unsupported-bottom-line.yaml', 'syncs.boursorama.items[0].bottomLine'];
        yield 'bottom line on the group that serves none' => ['syncs-twelvedata-bottom-line.yaml', 'syncs.twelvedata.items[0].bottomLine'];
        yield 'sleep window with equal bounds' => ['syncs-sleep-equal-bounds.yaml', 'sleep.windows[0].to'];
        yield 'sleep display mode the schema does not declare' => ['syncs-sleep-unknown-display-mode.yaml', 'sleep.displayMode'];
        yield 'sleep section without a timezone' => ['syncs-sleep-missing-timezone.yaml', 'timezone'];
    }

    #[DataProvider('provideRejectedFileCases')]
    public function testARejectedFileNamesWhatIsWrong(string $fixtureName, string $expectedMessageFragment): void
    {
        $loader = self::loaderFor($fixtureName);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        $loader->load();
    }

    private static function loaderFor(string $fixtureName): SyncsConfigLoader
    {
        return SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName);
    }
}
