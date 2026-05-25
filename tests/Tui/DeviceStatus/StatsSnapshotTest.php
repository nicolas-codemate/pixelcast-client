<?php

declare(strict_types=1);

namespace App\Tests\Tui\DeviceStatus;

use App\Tui\DeviceStatus\StatsSnapshot;
use PHPUnit\Framework\TestCase;

final class StatsSnapshotTest extends TestCase
{
    public function testUnreachableFactoryProducesUnreachableSnapshot(): void
    {
        $snapshot = StatsSnapshot::unreachable('connection failed');

        self::assertFalse($snapshot->reachable);
        self::assertSame('connection failed', $snapshot->errorMessage);
        self::assertNull($snapshot->uptimeSeconds);
        self::assertNull($snapshot->freeHeapBytes);
        self::assertNull($snapshot->firmwareVersion);
        self::assertNull($snapshot->ipAddress);
        self::assertNull($snapshot->brightness);
        self::assertNull($snapshot->autoRotate);
        self::assertNull($snapshot->defaultDurationSeconds);
    }

    public function testFromStatsPayloadMapsRepresentativePayload(): void
    {
        $payload = [
            'version' => '0.1.0-dev',
            'uptime' => 3661,
            'freeHeap' => 188_416,
            'maxAllocHeap' => 65_536,
            'brightness' => 128,
            'wifi' => [
                'ssid' => 'home-network',
                'rssi' => -55,
                'ip' => '192.168.1.42',
            ],
            'apps' => [
                'count' => 4,
                'current' => 'weather',
                'rotationEnabled' => true,
            ],
        ];

        $snapshot = StatsSnapshot::fromStatsPayload($payload);

        self::assertTrue($snapshot->reachable);
        self::assertNull($snapshot->errorMessage);
        self::assertSame(3661, $snapshot->uptimeSeconds);
        self::assertSame(188_416, $snapshot->freeHeapBytes);
        self::assertSame('0.1.0-dev', $snapshot->firmwareVersion);
        self::assertSame('192.168.1.42', $snapshot->ipAddress);
        self::assertSame(128, $snapshot->brightness);
        self::assertTrue($snapshot->autoRotate);
        self::assertNull($snapshot->defaultDurationSeconds);
    }

    public function testFromStatsPayloadReadsOptionalDefaultDurationWhenPresent(): void
    {
        $snapshot = StatsSnapshot::fromStatsPayload([
            'version' => '0.2.0',
            'defaultDuration' => 7,
        ]);

        self::assertTrue($snapshot->reachable);
        self::assertSame(7, $snapshot->defaultDurationSeconds);
    }

    public function testFromStatsPayloadHandlesMissingNestedKeys(): void
    {
        $snapshot = StatsSnapshot::fromStatsPayload([]);

        self::assertTrue($snapshot->reachable);
        self::assertNull($snapshot->uptimeSeconds);
        self::assertNull($snapshot->ipAddress);
        self::assertNull($snapshot->autoRotate);
    }

    public function testFromStatsPayloadIgnoresWrongTypes(): void
    {
        $snapshot = StatsSnapshot::fromStatsPayload([
            'uptime' => '123',
            'freeHeap' => 1024,
            'wifi' => 'not-an-array',
            'apps' => ['rotationEnabled' => 'yes'],
        ]);

        self::assertTrue($snapshot->reachable);
        self::assertNull($snapshot->uptimeSeconds);
        self::assertSame(1024, $snapshot->freeHeapBytes);
        self::assertNull($snapshot->ipAddress);
        self::assertNull($snapshot->autoRotate);
    }
}
