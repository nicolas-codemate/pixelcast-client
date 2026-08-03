<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\SyncGroupRegistry;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Config\Sync\WeatherSyncConfig;
use PHPUnit\Framework\TestCase;

final class SyncGroupRegistryTest extends TestCase
{
    public function testEveryDeclaredGroupExposesItsSyncType(): void
    {
        self::assertSame(['weather', 'coingecko', 'twelvedata'], SyncGroupRegistry::syncTypes());
    }

    public function testASyncTypeResolvesToItsConfigurationClass(): void
    {
        self::assertSame(WeatherSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('weather'));
        self::assertSame(CoinGeckoSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('coingecko'));
        self::assertSame(TwelveDataSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('twelvedata'));
    }

    public function testAnUnknownSyncTypeIsACodeInconsistency(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nope');

        SyncGroupRegistry::syncGroupClassFor('nope');
    }
}
