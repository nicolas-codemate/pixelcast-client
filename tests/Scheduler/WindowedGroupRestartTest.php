<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Message\SyncTrackerMessage;
use App\Schedule;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * What the schedule does when the consumer stops while the window is open and starts again while it
 * is closed: the catch-up run of `processOnlyLastMissedRun()` is dispatched at the restart, so a
 * windowed group is pushed once outside its hours. The behaviour is accepted and stated in the
 * README rather than worked around, the flag being schedule-wide.
 */
final class WindowedGroupRestartTest extends TestCase
{
    private const string FIXTURE_FILE = 'syncs-active-window.yaml';
    private const string SCHEDULE_NAME = 'default';
    private const string MARKET_TIMEZONE = 'Europe/Paris';

    public function testARestartOutsideTheWindowDispatchesTheMissedRunOnce(): void
    {
        $scheduleState = new ArrayAdapter();
        $clock = new MockClock('2026-08-07 16:50:00', self::MARKET_TIMEZONE);

        $runningConsumer = self::createMessageGenerator($scheduleState, $clock);
        self::assertSame([], self::trackerMessagesOf($runningConsumer));

        $clock->modify('2026-08-07 17:05:00');
        self::assertCount(1, self::trackerMessagesOf($runningConsumer), 'the windowed group runs while the market is open');

        $clock->modify('2026-08-10 08:00:00');
        $restartedConsumer = self::createMessageGenerator($scheduleState, $clock);

        self::assertCount(1, self::trackerMessagesOf($restartedConsumer), 'the run missed on Friday is caught up at the restart, before the reopening');
        self::assertSame([], self::trackerMessagesOf($restartedConsumer), 'the catch-up happens once, the group then waits for the reopening');
    }

    /**
     * @return list<SyncTrackerMessage>
     */
    private static function trackerMessagesOf(MessageGenerator $messageGenerator): array
    {
        $trackerMessages = [];

        foreach ($messageGenerator->getMessages() as $message) {
            if ($message instanceof SyncTrackerMessage) {
                $trackerMessages[] = $message;
            }
        }

        return $trackerMessages;
    }

    private static function createMessageGenerator(CacheInterface $scheduleState, ClockInterface $clock): MessageGenerator
    {
        $schedule = new Schedule(
            $scheduleState,
            SyncsConfigLoaderFactory::forConfigFile(\dirname(__DIR__).'/Config/Fixtures/'.self::FIXTURE_FILE),
        );

        return new MessageGenerator($schedule, self::SCHEDULE_NAME, $clock);
    }
}
