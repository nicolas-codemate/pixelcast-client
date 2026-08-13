<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\ClaudeSyncConfig;
use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\GitHubSyncConfig;
use App\Config\Sync\SyncGroupRegistry;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Config\Sync\WeatherSyncConfig;
use PHPUnit\Framework\TestCase;

final class SyncGroupRegistryTest extends TestCase
{
    public function testEveryDeclaredGroupExposesItsSyncType(): void
    {
        self::assertSame(['weather', 'coingecko', 'twelvedata', 'boursorama', 'claude', 'github'], SyncGroupRegistry::syncTypes());
    }

    public function testASyncTypeResolvesToItsConfigurationClass(): void
    {
        self::assertSame(WeatherSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('weather'));
        self::assertSame(CoinGeckoSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('coingecko'));
        self::assertSame(TwelveDataSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('twelvedata'));
        self::assertSame(BoursoramaSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('boursorama'));
        self::assertSame(ClaudeSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('claude'));
        self::assertSame(GitHubSyncConfig::class, SyncGroupRegistry::syncGroupClassFor('github'));
    }

    public function testAnUnknownSyncTypeIsACodeInconsistency(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nope');

        SyncGroupRegistry::syncGroupClassFor('nope');
    }
}
