<?php

declare(strict_types=1);

namespace App\Tests\Tui\Inspector;

use App\Tui\Inspector\InspectorPoller;
use App\Tui\Inspector\InspectorSnapshot;
use App\Tui\Inspector\InspectorTransport;
use PHPUnit\Framework\TestCase;

final class InspectorPollerTest extends TestCase
{
    public function testPollIfDueDoesNothingBeforeIntervalElapsed(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $poller = new InspectorPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 1.0);

        $firstResult = $poller->pollIfDue(0.4);
        $secondResult = $poller->pollIfDue(0.4);

        self::assertFalse($firstResult);
        self::assertFalse($secondResult);
        self::assertSame(0, $stubClient->fetchCount);
        self::assertNull($poller->getLatestSnapshot());
    }

    public function testPollIfDueFiresWhenAccumulatedDeltaReachesInterval(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $poller = new InspectorPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 1.0);

        $beforeInterval = $poller->pollIfDue(0.6);
        $atInterval = $poller->pollIfDue(0.5);

        self::assertFalse($beforeInterval);
        self::assertTrue($atInterval);
        self::assertSame(1, $stubClient->fetchCount);
        $latestSnapshot = $poller->getLatestSnapshot();
        self::assertNotNull($latestSnapshot);
        self::assertTrue($latestSnapshot->reachable);
    }

    public function testPollAlwaysFiresAndResetsAccumulator(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $poller = new InspectorPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 1.0);

        $poller->pollIfDue(0.5);
        $snapshot = $poller->poll();

        self::assertSame(1, $stubClient->fetchCount);
        self::assertSame($snapshot, $poller->getLatestSnapshot());

        $immediatelyAfter = $poller->pollIfDue(0.5);
        self::assertFalse($immediatelyAfter);
        self::assertSame(1, $stubClient->fetchCount);

        $afterFullInterval = $poller->pollIfDue(0.5);
        self::assertTrue($afterFullInterval);
        self::assertSame(2, $stubClient->fetchCount);
    }

    public function testNegativeDeltaIsClampedToZero(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $poller = new InspectorPoller($stubClient, 'http://example.invalid', pollIntervalSeconds: 1.0);

        $poller->pollIfDue(-100.0);
        $poller->pollIfDue(-100.0);

        self::assertSame(0, $stubClient->fetchCount);
        self::assertNull($poller->getLatestSnapshot());

        $fired = $poller->pollIfDue(1.0);
        self::assertTrue($fired);
        self::assertSame(1, $stubClient->fetchCount);
    }
}

final class CountingInspectorHttpClientStub implements InspectorTransport
{
    public int $fetchCount = 0;

    public function fetch(?string $baseUrl): InspectorSnapshot
    {
        ++$this->fetchCount;

        return InspectorSnapshot::fromInspectPayload([
            'state' => ['weather' => ['city' => 'Paris']],
            'requests' => [],
        ]);
    }
}
