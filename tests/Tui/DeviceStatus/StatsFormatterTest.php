<?php

declare(strict_types=1);

namespace App\Tests\Tui\DeviceStatus;

use App\Tui\DeviceStatus\StatsFormatter;
use App\Tui\DeviceStatus\StatsSnapshot;
use PHPUnit\Framework\TestCase;

final class StatsFormatterTest extends TestCase
{
    public function testFormatUptimeBelowOneMinuteShowsSeconds(): void
    {
        self::assertSame('0s', StatsFormatter::formatUptime(0));
        self::assertSame('45s', StatsFormatter::formatUptime(45));
    }

    public function testFormatUptimeBelowOneHourShowsMinutes(): void
    {
        self::assertSame('1m 05s', StatsFormatter::formatUptime(65));
        self::assertSame('59m 59s', StatsFormatter::formatUptime(3599));
    }

    public function testFormatUptimeBelowOneDayShowsHours(): void
    {
        self::assertSame('1h 01m 01s', StatsFormatter::formatUptime(3661));
        self::assertSame('23h 59m 59s', StatsFormatter::formatUptime(86_399));
    }

    public function testFormatUptimeWithDaysShowsDays(): void
    {
        self::assertSame('1d 00h 00m 00s', StatsFormatter::formatUptime(86_400));
        self::assertSame('2d 03h 04m 05s', StatsFormatter::formatUptime(2 * 86_400 + 3 * 3600 + 4 * 60 + 5));
    }

    public function testFormatUptimeWithNullOrNegativeReturnsNotAvailable(): void
    {
        self::assertSame('n/a', StatsFormatter::formatUptime(null));
        self::assertSame('n/a', StatsFormatter::formatUptime(-1));
    }

    public function testFormatBytesBelowOneKiloShowsBytes(): void
    {
        self::assertSame('0 B', StatsFormatter::formatBytes(0));
        self::assertSame('1023 B', StatsFormatter::formatBytes(1023));
    }

    public function testFormatBytesAboveOneKiloShowsKilobytesWithOneDecimal(): void
    {
        self::assertSame('1.0 KB', StatsFormatter::formatBytes(1024));
        self::assertSame('183.0 KB', StatsFormatter::formatBytes(187_392));
    }

    public function testFormatBytesWithNullReturnsNotAvailable(): void
    {
        self::assertSame('n/a', StatsFormatter::formatBytes(null));
    }

    public function testFormatBoolHandlesAllThreeStates(): void
    {
        self::assertSame('enabled', StatsFormatter::formatBool(true));
        self::assertSame('disabled', StatsFormatter::formatBool(false));
        self::assertSame('n/a', StatsFormatter::formatBool(null));
    }

    public function testFormatReachableSnapshotRendersAllFields(): void
    {
        $snapshot = StatsSnapshot::fromStatsPayload([
            'version' => '0.1.0-dev',
            'uptime' => 3661,
            'freeHeap' => 187_392,
            'brightness' => 128,
            'wifi' => ['ip' => '192.168.1.42'],
            'apps' => ['rotationEnabled' => true],
        ]);

        $output = StatsFormatter::format($snapshot);

        self::assertStringContainsString('Uptime:    1h 01m 01s', $output);
        self::assertStringContainsString('Free heap: 183.0 KB', $output);
        self::assertStringContainsString('IP:        192.168.1.42', $output);
        self::assertStringContainsString('Firmware:  0.1.0-dev', $output);
        self::assertStringContainsString('Brightness:       128', $output);
        self::assertStringContainsString('Auto rotate:      enabled', $output);
        self::assertStringContainsString('Default duration: n/a', $output);
    }

    public function testFormatSnapshotWithMissingFieldsRendersNotAvailable(): void
    {
        $output = StatsFormatter::format(StatsSnapshot::fromStatsPayload([]));

        self::assertStringContainsString('Uptime:    n/a', $output);
        self::assertStringContainsString('Free heap: n/a', $output);
        self::assertStringContainsString('IP:        n/a', $output);
        self::assertStringContainsString('Firmware:  n/a', $output);
        self::assertStringContainsString('Brightness:       n/a', $output);
        self::assertStringContainsString('Auto rotate:      n/a', $output);
        self::assertStringContainsString('Default duration: n/a', $output);
    }
}
