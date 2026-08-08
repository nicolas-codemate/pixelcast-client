<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\WeatherSyncConfig;
use App\Config\WeatherLocale;
use App\Config\WeatherUnits;
use App\Message\SyncWeatherMessage;
use PHPUnit\Framework\TestCase;

final class WeatherSyncConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validOptions(): array
    {
        return [
            'enabled' => true,
            'interval' => '30 minutes',
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'units' => 'metric',
            'locale' => 'fr',
        ];
    }

    public function testTheSyncTypeIsTheKeyUsedInTheConfigurationFile(): void
    {
        self::assertSame('weather', WeatherSyncConfig::syncType());
    }

    public function testAValidOptionMapIsHydrated(): void
    {
        $weatherSync = WeatherSyncConfig::fromOptions(self::validOptions());

        self::assertTrue($weatherSync->enabled);
        self::assertSame('30 minutes', $weatherSync->interval->expression);
        self::assertSame(48.8566, $weatherSync->latitude);
        self::assertSame(2.3522, $weatherSync->longitude);
        self::assertSame(WeatherUnits::Metric, $weatherSync->units);
        self::assertSame(WeatherLocale::French, $weatherSync->locale);
    }

    public function testWithoutFreshnessKeysTheSilenceToleratedIsThreeIntervalsAndTheBehaviourIsLeftToTheFirmware(): void
    {
        $weatherSync = WeatherSyncConfig::fromOptions(self::validOptions());

        self::assertSame(5400, $weatherSync->staleDeclaration->staleAfterInSeconds);
        self::assertNull($weatherSync->staleDeclaration->staleBehavior);
    }

    public function testWithoutAnActiveWindowTheGroupRunsAroundTheClock(): void
    {
        self::assertNull(WeatherSyncConfig::fromOptions(self::validOptions())->activeWindow);
    }

    public function testTheWeatherGroupRefusesABehaviourOnlyTheTrackerLayoutDraws(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.staleBehavior');

        WeatherSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'dim']));
    }

    public function testTheGroupIsTriggeredByAWeatherSyncMessage(): void
    {
        $weatherSync = WeatherSyncConfig::fromOptions(self::validOptions());

        self::assertInstanceOf(SyncWeatherMessage::class, $weatherSync->syncMessage());
    }

    public function testAnIntervalTheSchedulerCannotParseNamesTheOption(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.interval');

        WeatherSyncConfig::fromOptions(array_merge(self::validOptions(), ['interval' => 'every fortnight']));
    }

    public function testAMissingOptionNamesItsFullPath(): void
    {
        $options = self::validOptions();
        unset($options['latitude']);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.latitude');

        WeatherSyncConfig::fromOptions($options);
    }

    public function testWithoutAnActiveWindowTheGroupHasAlwaysBeenDispatchable(): void
    {
        $activity = WeatherSyncConfig::fromOptions(self::validOptions())->activityAt(new \DateTimeImmutable('2026-08-08 03:00:00'));

        self::assertTrue($activity->isActive);
        self::assertNull($activity->secondsSinceBecameActive);
    }

    public function testAnActiveWindowSaysWhetherTheGroupIsDispatchableAndSinceWhen(): void
    {
        $weatherSync = WeatherSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'activeWindow' => ['days' => ['mon'], 'from' => '08:00', 'to' => '18:00', 'timezone' => 'Europe/Paris'],
        ]));

        $insideTheWindow = $weatherSync->activityAt(new \DateTimeImmutable('2026-08-03 16:00:00', new \DateTimeZone('Europe/Paris')));
        self::assertTrue($insideTheWindow->isActive);
        self::assertSame(28800, $insideTheWindow->secondsSinceBecameActive);

        $outsideTheWindow = $weatherSync->activityAt(new \DateTimeImmutable('2026-08-03 19:00:00', new \DateTimeZone('Europe/Paris')));
        self::assertFalse($outsideTheWindow->isActive);
        self::assertNull($outsideTheWindow->secondsSinceBecameActive);
    }

    public function testAnUnknownUnitsValueNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.units');

        WeatherSyncConfig::fromOptions(array_merge(self::validOptions(), ['units' => 'kelvin']));
    }
}
