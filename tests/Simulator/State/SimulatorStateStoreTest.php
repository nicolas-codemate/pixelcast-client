<?php

declare(strict_types=1);

namespace App\Tests\Simulator\State;

use App\Simulator\Logging\RequestLog;
use App\Simulator\Logging\RequestLogEntry;
use App\Simulator\State\BrightnessState;
use App\Simulator\State\CustomAppState;
use App\Simulator\State\GaugeState;
use App\Simulator\State\IconState;
use App\Simulator\State\IndicatorState;
use App\Simulator\State\NotificationState;
use App\Simulator\State\ResettableState;
use App\Simulator\State\SettingsState;
use App\Simulator\State\SimulatorStateStore;
use App\Simulator\State\TrackerState;
use App\Simulator\State\WeatherState;
use App\Simulator\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SimulatorStateStoreTest extends TestCase
{
    private string $temporaryDirectory;
    private string $stateFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-simulator-state-'.bin2hex(random_bytes(6));
        $this->stateFilePath = $this->temporaryDirectory.'/state.json';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testSavedDomainStateIsRestoredIntoFreshInstances(): void
    {
        $savedWeather = new WeatherState();
        $savedWeather->update(['icon' => 'w_rain', 'temp' => 9], [['icon' => 'w_clear_day', 'temp' => 14]], ['hours' => [['h' => 15, 'temp' => 22]]]);

        $savedTrackers = new TrackerState();
        $savedTrackers->upsert('btc', ['name' => 'btc', 'value' => '42']);

        $savedIcons = new IconState();
        $savedIcons->register('w_rain', 512, 'gif');

        $savedIndicators = new IndicatorState();
        $savedIndicators->set(2, ['color' => '#ff0000']);

        $savedSettings = new SettingsState();
        $savedSettings->patch(['autoRotate' => false]);

        $savedBrightness = new BrightnessState();
        $savedBrightness->set(180);

        $savedNotifications = new NotificationState();
        $savedNotifications->enqueue(['id' => 'notif_1', 'text' => 'hello']);

        $savedCustomApps = new CustomAppState();
        $savedCustomApps->upsert('clock', ['name' => 'clock', 'text' => 'tic']);

        $this->createStore(
            [
                $savedWeather,
                $savedTrackers,
                $savedIcons,
                $savedIndicators,
                $savedSettings,
                $savedBrightness,
                $savedNotifications,
                $savedCustomApps,
            ],
            new RequestLog(),
        )->save();

        $restoredWeather = new WeatherState();
        $restoredTrackers = new TrackerState();
        $restoredIcons = new IconState();
        $restoredIndicators = new IndicatorState();
        $restoredSettings = new SettingsState();
        $restoredBrightness = new BrightnessState();
        $restoredNotifications = new NotificationState();
        $restoredCustomApps = new CustomAppState();

        $this->createStore(
            [
                $restoredWeather,
                $restoredTrackers,
                $restoredIcons,
                $restoredIndicators,
                $restoredSettings,
                $restoredBrightness,
                $restoredNotifications,
                $restoredCustomApps,
            ],
            new RequestLog(),
        )->load();

        self::assertSame(['icon' => 'w_rain', 'temp' => 9], $restoredWeather->current());
        self::assertSame([['icon' => 'w_clear_day', 'temp' => 14]], $restoredWeather->forecast());
        self::assertSame(
            $savedWeather->lastUpdatedAt()?->format(\DateTimeInterface::ATOM),
            $restoredWeather->lastUpdatedAt()?->format(\DateTimeInterface::ATOM),
        );

        self::assertSame(['name' => 'btc', 'value' => '42'], $restoredTrackers->get('btc'));
        self::assertSame($savedIcons->snapshot(), $restoredIcons->snapshot());
        self::assertSame(['color' => '#ff0000'], $restoredIndicators->get(2));
        self::assertNull($restoredIndicators->get(1));
        self::assertSame($savedSettings->current(), $restoredSettings->current());
        self::assertSame(180, $restoredBrightness->current());
        self::assertSame($savedNotifications->list(), $restoredNotifications->list());
        self::assertSame(['name' => 'clock', 'text' => 'tic'], $restoredCustomApps->get('clock'));
    }

    public function testPushInstantsAreRestoredForTrackersGaugesAndApps(): void
    {
        $savedTrackers = new TrackerState();
        $savedTrackers->upsert('btc', ['value' => '42']);

        $savedGauges = new GaugeState();
        $savedGauges->upsert('claude', ['title' => 'Claude']);

        $savedCustomApps = new CustomAppState();
        $savedCustomApps->upsert('clock', ['text' => 'tic']);

        $this->createStore([$savedTrackers, $savedGauges, $savedCustomApps], new RequestLog())->save();

        $restoredTrackers = new TrackerState();
        $restoredGauges = new GaugeState();
        $restoredCustomApps = new CustomAppState();

        $this->createStore([$restoredTrackers, $restoredGauges, $restoredCustomApps], new RequestLog())->load();

        self::assertSame(
            $savedTrackers->pushedAt('btc')?->format(\DateTimeInterface::ATOM),
            $restoredTrackers->pushedAt('btc')?->format(\DateTimeInterface::ATOM),
        );
        self::assertSame(
            $savedGauges->pushedAt('claude')?->format(\DateTimeInterface::ATOM),
            $restoredGauges->pushedAt('claude')?->format(\DateTimeInterface::ATOM),
        );
        self::assertSame(
            $savedCustomApps->pushedAt('clock')?->format(\DateTimeInterface::ATOM),
            $restoredCustomApps->pushedAt('clock')?->format(\DateTimeInterface::ATOM),
        );
        self::assertNotNull($restoredTrackers->pushedAt('btc'));
    }

    public function testIconsAreRestoredAsANameKeyedMap(): void
    {
        $savedIcons = new IconState();
        $savedIcons->register('w_rain');
        $savedIcons->register('w_snow');

        $this->createStore([$savedIcons], new RequestLog())->save();

        $restoredIcons = new IconState();
        $this->createStore([$restoredIcons], new RequestLog())->load();

        self::assertTrue($restoredIcons->has('w_rain'));
        self::assertTrue($restoredIcons->has('w_snow'));
        self::assertTrue($restoredIcons->delete('w_rain'));
        self::assertSame(1, $restoredIcons->count());
    }

    public function testRequestLogSurvivesASaveLoadRoundTrip(): void
    {
        $savedLog = new RequestLog();
        $savedLog->record($this->createLogEntry('/weather'));

        $this->createStore([], $savedLog)->save();

        $restoredLog = new RequestLog();
        $this->createStore([], $restoredLog)->load();

        self::assertSame(1, $restoredLog->count());

        $restoredEntry = $restoredLog->snapshotEntries()[0] ?? null;
        self::assertIsArray($restoredEntry);
        self::assertSame('/weather', $restoredEntry['path'] ?? null);
        self::assertSame(['valid' => true], $restoredEntry['validation'] ?? null);
    }

    public function testRestoreReappliesTheRingBufferCapacityAndDropsBrokenEntries(): void
    {
        $persistedEntries = [];
        for ($index = 1; $index <= 60; ++$index) {
            $persistedEntries[] = $this->createLogEntry('/p'.$index)->toArray();
        }
        $persistedEntries[] = ['method' => 'POST'];
        $persistedEntries[] = ['method' => 'POST', 'path' => '/broken', 'timestamp' => 'not-a-date'];

        $this->writeStateFile(['states' => [], 'requestLog' => $persistedEntries]);

        $restoredLog = new RequestLog();
        $this->createStore([], $restoredLog)->load();

        self::assertSame(50, $restoredLog->count());

        $restoredEntries = $restoredLog->snapshotEntries();
        self::assertSame('/p11', $restoredEntries[0]['path'] ?? null);
        self::assertSame('/p60', $restoredEntries[49]['path'] ?? null);
    }

    public function testPurgeResetsEveryStateAndDeletesTheStateFile(): void
    {
        $weather = new WeatherState();
        $weather->update(['icon' => 'w_rain', 'temp' => 9], [], null);

        $requestLog = new RequestLog();
        $requestLog->record($this->createLogEntry('/weather'));

        $store = $this->createStore([$weather], $requestLog);
        $store->save();
        self::assertFileExists($this->stateFilePath);

        $store->purge();

        self::assertNull($weather->current());
        self::assertSame(0, $requestLog->count());
        self::assertFileDoesNotExist($this->stateFilePath);
    }

    public function testCorruptStateFileLeavesDefaultsInPlace(): void
    {
        new Filesystem()->dumpFile($this->stateFilePath, '{');

        $weather = new WeatherState();
        $brightness = new BrightnessState();
        $requestLog = new RequestLog();

        $this->createStore([$weather, $brightness], $requestLog)->load();

        self::assertNull($weather->current());
        self::assertSame(128, $brightness->current());
        self::assertSame(0, $requestLog->count());
    }

    public function testMissingStateFileLeavesDefaultsInPlace(): void
    {
        $weather = new WeatherState();
        $requestLog = new RequestLog();

        $this->createStore([$weather], $requestLog)->load();

        self::assertNull($weather->current());
        self::assertSame(0, $requestLog->count());
    }

    /**
     * @param list<ResettableState> $states
     */
    private function createStore(array $states, RequestLog $requestLog): SimulatorStateStore
    {
        return new SimulatorStateStore($states, $requestLog, $this->stateFilePath);
    }

    private function createLogEntry(string $path): RequestLogEntry
    {
        return new RequestLogEntry(
            method: 'POST',
            path: $path,
            body: null,
            timestamp: new \DateTimeImmutable('2026-08-02T10:00:00+00:00'),
            validationResult: ValidationResult::success(),
        );
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    private function writeStateFile(array $persistedState): void
    {
        new Filesystem()->dumpFile(
            $this->stateFilePath,
            json_encode($persistedState, \JSON_THROW_ON_ERROR),
        );
    }
}
