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
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class SyncsConfigLoaderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const int INITIAL_MODIFICATION_TIME = 1700000000;

    private string $temporaryDirectory;
    private string $configFilePath;
    private RecordingLoggerStub $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-config-reload-'.bin2hex(random_bytes(6));
        $this->configFilePath = $this->temporaryDirectory.'/pixelcast.yaml';
        $this->logger = new RecordingLoggerStub();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

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
        self::assertNotNull($config->deviceSleep->timezone);
        self::assertSame('Europe/Paris', $config->deviceSleep->timezone->getName());
    }

    public function testAFileWithoutADeviceSectionCarriesNoDeviceConfig(): void
    {
        $config = self::loaderFor('syncs-valid.yaml')->load();

        self::assertNull($config->device);
    }

    public function testTheDeviceTimezoneIsTheDefaultOfTheSleepScheduleAndOfEveryActiveWindow(): void
    {
        $config = self::loaderFor('syncs-device-timezone.yaml')->load();

        self::assertNotNull($config->deviceSleep);
        self::assertNotNull($config->deviceSleep->timezone);
        self::assertSame('Europe/Paris', $config->deviceSleep->timezone->getName());
        self::assertStringEndsWith('Europe/Paris', (string) $config->sleepSchedule());
        self::assertStringEndsWith('Europe/Paris', (string) $config->syncGroupOfType(BoursoramaSyncConfig::class)->activeWindow);

        $trackedItem = $config->syncGroupOfType(BoursoramaSyncConfig::class)->items[0];
        self::assertNotNull($trackedItem->activeWindow);
        self::assertStringEndsWith('Europe/Paris', (string) $trackedItem->activeWindow);
    }

    public function testALocalTimezoneWinsOverTheDeviceOne(): void
    {
        $config = self::loaderFor('syncs-device-timezone-overridden.yaml')->load();

        self::assertStringEndsWith('America/New_York', (string) $config->sleepSchedule());
        self::assertStringEndsWith('America/New_York', (string) $config->syncGroupOfType(BoursoramaSyncConfig::class)->activeWindow);
    }

    public function testAFileDeclaringDeviceSettingsKeepsThemAllOnTheDeviceSection(): void
    {
        $config = self::loaderFor('syncs-device-settings.yaml')->load();

        self::assertNotNull($config->device);
        self::assertNotNull($config->device->timezone);
        self::assertSame('Europe/Paris', $config->device->timezone->getName());
        self::assertNotNull($config->device->brightness);
        self::assertSame(120, $config->device->brightness->level);
        self::assertTrue($config->device->autoRotate);
        self::assertSame(8000, $config->device->defaultDurationMilliseconds);
        self::assertSame(12000, $config->device->weatherDurationMilliseconds);
        self::assertSame('pool.ntp.org', $config->device->ntpServer);
    }

    public function testAnEnabledSleepWithoutAnyTimezoneNamesBothKeys(): void
    {
        try {
            self::loaderFor('syncs-sleep-missing-timezone.yaml')->load();
        } catch (PixelCastConfigException $rejection) {
            self::assertStringContainsString('sleep.timezone', $rejection->getMessage());
            self::assertStringContainsString('device.timezone', $rejection->getMessage());

            return;
        }

        self::fail('An enabled sleep section without any timezone at all was accepted.');
    }

    public function testAnActiveWindowWithoutAnyTimezoneNamesBothKeys(): void
    {
        try {
            self::loaderFor('syncs-window-missing-timezone.yaml')->load();
        } catch (PixelCastConfigException $rejection) {
            self::assertStringContainsString('syncs.boursorama.activeWindow.timezone', $rejection->getMessage());
            self::assertStringContainsString('device.timezone', $rejection->getMessage());

            return;
        }

        self::fail('An active window without any timezone at all was accepted.');
    }

    public function testAFileWithoutASleepSectionCarriesNoSchedule(): void
    {
        $config = self::loaderFor('syncs-valid.yaml')->load();

        self::assertNull($config->deviceSleep);
        self::assertNull($config->sleepSchedule());
    }

    public function testADisabledSleepSectionLoadsWithoutATimezoneAndCarriesNoSchedule(): void
    {
        $config = self::loaderFor('syncs-sleep-disabled.yaml')->load();

        self::assertNotNull($config->deviceSleep);
        self::assertNull($config->deviceSleep->timezone);
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

    public function testTheFileIsReadOnlyOnceWhenItDoesNotChange(): void
    {
        $this->writeConfigFile('syncs-valid.yaml', self::INITIAL_MODIFICATION_TIME);
        $loader = $this->loaderOnTheWrittenFile();
        $firstLoad = $loader->load();

        $this->writeConfigFile('syncs-stale-overrides.yaml', self::INITIAL_MODIFICATION_TIME);

        self::assertSame($firstLoad, $loader->load());
        self::assertSame(['weather'], array_keys($loader->load()->enabledSyncGroups()));
    }

    public function testAnEditedFileIsReadAgainOnTheNextLoad(): void
    {
        $this->writeConfigFile('syncs-valid.yaml', self::INITIAL_MODIFICATION_TIME);
        $loader = $this->loaderOnTheWrittenFile();
        $loader->load();

        $this->writeConfigFile('syncs-stale-overrides.yaml', self::INITIAL_MODIFICATION_TIME + 60);

        self::assertSame(['weather', 'boursorama'], array_keys($loader->load()->enabledSyncGroups()));
        self::assertSame([], $this->logger->records);
    }

    public function testABrokenEditKeepsTheConfigurationInUseAndIsReportedOnce(): void
    {
        $this->writeConfigFile('syncs-valid.yaml', self::INITIAL_MODIFICATION_TIME);
        $loader = $this->loaderOnTheWrittenFile();
        $configInUse = $loader->load();

        $this->writeConfigFile('syncs-invalid-syntax.yaml', self::INITIAL_MODIFICATION_TIME + 60);

        self::assertSame($configInUse, $loader->load());
        self::assertSame($configInUse, $loader->load());
        self::assertSame($configInUse, $loader->load());

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertSame($this->configFilePath, $this->logger->records[0]['context']['config_file']);
    }

    public function testASecondBrokenEditIsReportedAgain(): void
    {
        $this->writeConfigFile('syncs-valid.yaml', self::INITIAL_MODIFICATION_TIME);
        $loader = $this->loaderOnTheWrittenFile();
        $loader->load();

        $this->writeConfigFile('syncs-invalid-syntax.yaml', self::INITIAL_MODIFICATION_TIME + 60);
        $loader->load();

        $this->writeConfigFile('syncs-unknown-group.yaml', self::INITIAL_MODIFICATION_TIME + 120);
        $loader->load();

        self::assertCount(2, $this->logger->records);
    }

    public function testAFileFixedAfterABrokenEditIsPickedUp(): void
    {
        $this->writeConfigFile('syncs-valid.yaml', self::INITIAL_MODIFICATION_TIME);
        $loader = $this->loaderOnTheWrittenFile();
        $loader->load();

        $this->writeConfigFile('syncs-invalid-syntax.yaml', self::INITIAL_MODIFICATION_TIME + 60);
        $loader->load();

        $this->writeConfigFile('syncs-stale-overrides.yaml', self::INITIAL_MODIFICATION_TIME + 120);

        self::assertSame(['weather', 'boursorama'], array_keys($loader->load()->enabledSyncGroups()));
    }

    public function testAFileThatDisappearsKeepsTheConfigurationInUse(): void
    {
        $this->writeConfigFile('syncs-valid.yaml', self::INITIAL_MODIFICATION_TIME);
        $loader = $this->loaderOnTheWrittenFile();
        $configInUse = $loader->load();

        new Filesystem()->remove($this->configFilePath);

        self::assertSame($configInUse, $loader->load());
        self::assertCount(1, $this->logger->records);
    }

    public function testAnInvalidFileOnTheFirstReadStillStopsTheProcess(): void
    {
        $this->writeConfigFile('syncs-invalid-syntax.yaml', self::INITIAL_MODIFICATION_TIME);
        $loader = $this->loaderOnTheWrittenFile();

        try {
            $loader->load();
        } catch (PixelCastConfigException) {
            self::assertSame([], $this->logger->records);

            return;
        }

        self::fail('An invalid configuration was accepted on the very first read.');
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
        $loader = new SyncsConfigLoader(self::FIXTURES_DIR.'/syncs-valid.yaml', self::FIXTURES_DIR.'/does-not-exist.json', new NullLogger());

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
        yield 'enabled sleep section without a timezone' => ['syncs-sleep-missing-timezone.yaml', 'Missing required PixelCast config key "sleep.timezone"'];
        yield 'window without any timezone' => ['syncs-window-missing-timezone.yaml', 'Missing required PixelCast config key "syncs.boursorama.activeWindow.timezone"'];
        yield 'brightness above what the panel accepts' => ['syncs-device-brightness-out-of-bounds.yaml', 'device.brightness'];
        yield 'weather duration below what the weather app needs' => ['syncs-device-weather-duration-out-of-bounds.yaml', 'device.weatherDuration'];
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

    /**
     * The committed fixtures cannot have their modification time moved, so the reload cases work on
     * a copy whose time the test owns.
     */
    private function writeConfigFile(string $fixtureName, int $modificationTime): void
    {
        new Filesystem()->copy(self::FIXTURES_DIR.'/'.$fixtureName, $this->configFilePath, true);
        touch($this->configFilePath, $modificationTime);
    }

    private function loaderOnTheWrittenFile(): SyncsConfigLoader
    {
        return SyncsConfigLoaderFactory::forConfigFile($this->configFilePath, $this->logger);
    }
}
