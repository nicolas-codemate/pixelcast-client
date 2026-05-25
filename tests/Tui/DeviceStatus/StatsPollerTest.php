<?php

declare(strict_types=1);

namespace App\Tests\Tui\DeviceStatus;

use App\Tui\DeviceStatus\StatsPoller;
use App\Tui\DeviceStatus\StatsSnapshot;
use App\Tui\DeviceStatus\StatsTransport;
use PHPUnit\Framework\TestCase;

final class StatsPollerTest extends TestCase
{
    public function testPollIfDueDoesNothingBeforeIntervalElapsed(): void
    {
        $stubClient = new CountingStatsHttpClientStub();
        $poller = new StatsPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 2.0);

        $firstResult = $poller->pollIfDue(0.8);
        $secondResult = $poller->pollIfDue(0.8);

        self::assertFalse($firstResult);
        self::assertFalse($secondResult);
        self::assertSame(0, $stubClient->fetchCount);
        self::assertNull($poller->getLatestSnapshot());
    }

    public function testPollIfDueFiresWhenAccumulatedDeltaReachesInterval(): void
    {
        $stubClient = new CountingStatsHttpClientStub();
        $poller = new StatsPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 2.0);

        $beforeInterval = $poller->pollIfDue(1.2);
        $atInterval = $poller->pollIfDue(1.0);

        self::assertFalse($beforeInterval);
        self::assertTrue($atInterval);
        self::assertSame(1, $stubClient->fetchCount);
        $latestSnapshot = $poller->getLatestSnapshot();
        self::assertNotNull($latestSnapshot);
        self::assertTrue($latestSnapshot->reachable);
    }

    public function testPollAlwaysFiresAndResetsAccumulator(): void
    {
        $stubClient = new CountingStatsHttpClientStub();
        $poller = new StatsPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 2.0);

        $poller->pollIfDue(1.0);
        $snapshot = $poller->poll();

        self::assertSame(1, $stubClient->fetchCount);
        self::assertSame($snapshot, $poller->getLatestSnapshot());

        $immediatelyAfter = $poller->pollIfDue(1.0);
        self::assertFalse($immediatelyAfter);
        self::assertSame(1, $stubClient->fetchCount);

        $afterFullInterval = $poller->pollIfDue(1.0);
        self::assertTrue($afterFullInterval);
        self::assertSame(2, $stubClient->fetchCount);
    }

    public function testNegativeDeltaIsClampedToZero(): void
    {
        $stubClient = new CountingStatsHttpClientStub();
        $poller = new StatsPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 2.0);

        $poller->pollIfDue(-100.0);
        $poller->pollIfDue(-100.0);

        self::assertSame(0, $stubClient->fetchCount);
        self::assertNull($poller->getLatestSnapshot());

        $fired = $poller->pollIfDue(2.0);
        self::assertTrue($fired);
        self::assertSame(1, $stubClient->fetchCount);
    }
}

final class CountingStatsHttpClientStub implements StatsTransport
{
    public int $fetchCount = 0;

    public function fetch(?string $baseUrl): StatsSnapshot
    {
        ++$this->fetchCount;

        return StatsSnapshot::fromStatsPayload([
            'version' => '0.1.0-dev',
            'uptime' => 42,
            'freeHeap' => 1024,
            'wifi' => ['ip' => '10.0.0.1'],
            'apps' => ['rotationEnabled' => false],
            'brightness' => 100,
        ]);
    }
}
