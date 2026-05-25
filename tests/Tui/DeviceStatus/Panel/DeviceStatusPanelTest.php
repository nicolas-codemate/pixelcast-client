<?php

declare(strict_types=1);

namespace App\Tests\Tui\DeviceStatus\Panel;

use App\Tui\DeviceStatus\Panel\DeviceStatusPanel;
use App\Tui\DeviceStatus\StatsSnapshot;
use PHPUnit\Framework\TestCase;

final class DeviceStatusPanelTest extends TestCase
{
    public function testInitialBodyShowsNoData(): void
    {
        $panel = new DeviceStatusPanel();

        self::assertSame('Device Status', $panel->headerText());
        self::assertSame('No data', $panel->bodyText());
    }

    public function testUpdateWithNullSnapshotShowsNoData(): void
    {
        $panel = new DeviceStatusPanel();
        $panel->update(null, busy: false);

        self::assertSame('Device Status', $panel->headerText());
        self::assertSame('No data', $panel->bodyText());
    }

    public function testUpdateWithUnreachableSnapshotShowsUnreachable(): void
    {
        $panel = new DeviceStatusPanel();
        $panel->update(StatsSnapshot::unreachable('connection failed'), busy: false);

        self::assertSame('Device Status', $panel->headerText());
        self::assertSame('Unreachable', $panel->bodyText());
    }

    public function testUpdateWithBusyTrueShowsPollingHeader(): void
    {
        $panel = new DeviceStatusPanel();
        $panel->update(StatsSnapshot::unreachable('timeout'), busy: true);

        self::assertSame('Device Status  polling...', $panel->headerText());
    }

    public function testUpdateWithReachableSnapshotRendersFields(): void
    {
        $panel = new DeviceStatusPanel();
        $snapshot = StatsSnapshot::fromStatsPayload([
            'version' => '0.1.0-dev',
            'uptime' => 3661,
            'freeHeap' => 187_392,
            'brightness' => 128,
            'wifi' => ['ip' => '192.168.1.42'],
            'apps' => ['rotationEnabled' => true],
        ]);

        $panel->update($snapshot, busy: false);

        $body = $panel->bodyText();
        self::assertStringContainsString('Uptime:', $body);
        self::assertStringContainsString('Free heap:', $body);
        self::assertStringContainsString('IP:        192.168.1.42', $body);
        self::assertStringContainsString('Firmware:  0.1.0-dev', $body);
        self::assertStringContainsString('Brightness:       128', $body);
    }
}
