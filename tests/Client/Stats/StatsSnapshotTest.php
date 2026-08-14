<?php

declare(strict_types=1);

namespace App\Tests\Client\Stats;

use App\Client\Stats\StatsSnapshot;
use PHPUnit\Framework\TestCase;

final class StatsSnapshotTest extends TestCase
{
    public function testTheFullPayloadOfTheSimulatorIsReadAndItsExtraKeysAreIgnored(): void
    {
        $statsSnapshot = StatsSnapshot::fromResponseBody([
            'version' => '0.1.0-sim',
            'uptime' => 3661,
            'freeHeap' => 188_416,
            'maxAllocHeap' => 81_920,
            'brightness' => 128,
            'wifi' => [
                'ssid' => 'simulator',
                'rssi' => -45,
                'ip' => '127.0.0.1',
            ],
            'display' => ['width' => 64, 'height' => 8],
            'mqtt' => ['connected' => false],
            'apps' => ['count' => 0, 'current' => '', 'rotationEnabled' => true],
            'filesystem' => ['ready' => true, 'total' => 1_048_576, 'used' => 0],
            'chipModel' => 'ESP32-SIM',
            'cpuFreq' => 240,
        ]);

        self::assertSame('0.1.0-sim', $statsSnapshot->firmwareVersion);
        self::assertSame(3661, $statsSnapshot->uptimeSeconds);
        self::assertSame(188_416, $statsSnapshot->freeHeapBytes);
        self::assertSame(81_920, $statsSnapshot->maxAllocatableHeapBytes);
        self::assertSame(128, $statsSnapshot->brightness);
        self::assertNotNull($statsSnapshot->wifi);
        self::assertSame('simulator', $statsSnapshot->wifi->ssid);
        self::assertSame(-45, $statsSnapshot->wifi->signalStrengthDbm);
        self::assertSame('127.0.0.1', $statsSnapshot->wifi->ipAddress);
    }

    public function testAnEmptyBodyLeavesEveryFieldEmpty(): void
    {
        $statsSnapshot = StatsSnapshot::fromResponseBody([]);

        self::assertNull($statsSnapshot->firmwareVersion);
        self::assertNull($statsSnapshot->uptimeSeconds);
        self::assertNull($statsSnapshot->freeHeapBytes);
        self::assertNull($statsSnapshot->maxAllocatableHeapBytes);
        self::assertNull($statsSnapshot->brightness);
        self::assertNull($statsSnapshot->wifi);
    }

    public function testAWifiNodeOfAnUnexpectedTypeLeavesNoWifiStatus(): void
    {
        $statsSnapshot = StatsSnapshot::fromResponseBody([
            'version' => '0.1.0-dev',
            'wifi' => 'not-an-object',
        ]);

        self::assertSame('0.1.0-dev', $statsSnapshot->firmwareVersion);
        self::assertNull($statsSnapshot->wifi);
    }

    public function testFieldsOfAnUnexpectedTypeAreDroppedWithoutLosingTheOthers(): void
    {
        $statsSnapshot = StatsSnapshot::fromResponseBody([
            'version' => 42,
            'uptime' => '3661',
            'freeHeap' => 188_416,
            'maxAllocHeap' => 81_920.5,
            'wifi' => ['ssid' => true, 'rssi' => '-45', 'ip' => '127.0.0.1'],
        ]);

        self::assertNull($statsSnapshot->firmwareVersion);
        self::assertNull($statsSnapshot->uptimeSeconds);
        self::assertSame(188_416, $statsSnapshot->freeHeapBytes);
        self::assertNull($statsSnapshot->maxAllocatableHeapBytes);
        self::assertNotNull($statsSnapshot->wifi);
        self::assertNull($statsSnapshot->wifi->ssid);
        self::assertNull($statsSnapshot->wifi->signalStrengthDbm);
        self::assertSame('127.0.0.1', $statsSnapshot->wifi->ipAddress);
    }

    public function testAPartialWifiNodeKeepsWhatItCarries(): void
    {
        $statsSnapshot = StatsSnapshot::fromResponseBody([
            'wifi' => ['ssid' => 'home-network'],
        ]);

        self::assertNotNull($statsSnapshot->wifi);
        self::assertSame('home-network', $statsSnapshot->wifi->ssid);
        self::assertNull($statsSnapshot->wifi->signalStrengthDbm);
        self::assertNull($statsSnapshot->wifi->ipAddress);
    }
}
