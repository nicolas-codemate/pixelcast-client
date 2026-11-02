<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\HomeAssistantSyncConfig;
use App\Message\SyncHomeAssistantMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HomeAssistantSyncConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validOptions(): array
    {
        return [
            'enabled' => true,
            'interval' => '10 minutes',
            'items' => [
                ['entity' => 'sensor.salon_temperature', 'label' => 'Salon', 'icon' => 'thermo', 'color' => '#00d4ff'],
                ['entity' => 'sensor.exterieur_temperature', 'label' => 'Dehors'],
            ],
        ];
    }

    public function testTheSyncTypeIsTheKeyUsedInTheConfigurationFile(): void
    {
        self::assertSame('homeassistant', HomeAssistantSyncConfig::syncType());
    }

    public function testAValidOptionMapIsHydratedWithItsSensorsInTheirOrder(): void
    {
        $homeAssistantSync = HomeAssistantSyncConfig::fromOptions(self::validOptions(), null);

        self::assertTrue($homeAssistantSync->enabled);
        self::assertSame('10 minutes', $homeAssistantSync->interval->expression);
        self::assertSame(['sensor.salon_temperature', 'sensor.exterieur_temperature'], array_column($homeAssistantSync->items, 'entityId'));
        self::assertSame(['Salon', 'Dehors'], array_column($homeAssistantSync->items, 'label'));
    }

    public function testTheOptionalIconAndColourOfASensorAreReadOrLeftOut(): void
    {
        [$described, $bare] = HomeAssistantSyncConfig::fromOptions(self::validOptions(), null)->items;

        self::assertSame('thermo', $described->iconName);
        self::assertSame('#00D4FF', $described->valueColor?->hexCode);
        self::assertNull($bare->iconName);
        self::assertNull($bare->valueColor);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideSensorCountsTheAppCannotHold(): iterable
    {
        yield 'none' => [0];
        yield 'five' => [5];
    }

    #[DataProvider('provideSensorCountsTheAppCannotHold')]
    public function testASensorCountTheAppCannotHoldNamesItsFullPath(int $sensorCount): void
    {
        $items = array_fill(0, $sensorCount, ['entity' => 'sensor.salon_temperature', 'label' => 'Salon']);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.homeassistant.items');

        HomeAssistantSyncConfig::fromOptions(array_merge(self::validOptions(), ['items' => $items]), null);
    }

    public function testASensorWithoutAnEntityNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.homeassistant.items[1].entity');

        HomeAssistantSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'items' => [['entity' => 'sensor.salon_temperature', 'label' => 'Salon'], ['label' => 'Dehors']],
        ]), null);
    }

    public function testAColourThatIsNotAHexCodeNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.homeassistant.items[0].color');

        HomeAssistantSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'items' => [['entity' => 'sensor.salon_temperature', 'label' => 'Salon', 'color' => 'blue']],
        ]), null);
    }

    public function testTheBehavioursOnlyTheTrackerAndGaugeLayoutsDrawAreRefused(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.homeassistant.staleBehavior');

        HomeAssistantSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'dim']), null);
    }

    public function testWithoutFreshnessKeysTheSilenceToleratedIsThreeIntervals(): void
    {
        $homeAssistantSync = HomeAssistantSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'hide']), null);

        self::assertSame(1800, $homeAssistantSync->staleDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $homeAssistantSync->staleDeclaration->staleBehavior);
    }

    public function testTheGroupIsTriggeredByAHomeAssistantSyncMessage(): void
    {
        self::assertInstanceOf(SyncHomeAssistantMessage::class, HomeAssistantSyncConfig::fromOptions(self::validOptions(), null)->syncMessage());
    }
}
