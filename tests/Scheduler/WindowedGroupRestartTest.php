<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Message\SyncTrackerMessage;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * What the schedule does when the consumer stops while the window is open and starts again while it
 * is closed: `processOnlyLastMissedRun()` absorbs the cycles missed in between rather than
 * dispatching one of them at the restart, so a windowed group is never pushed outside its hours.
 * The cycle missed while the consumer was down is lost, which is the point of the flag: a quote
 * three days old is worth nothing.
 */
final class WindowedGroupRestartTest extends TestCase
{
    private const string FIXTURE_FILE = 'syncs-active-window.yaml';
    private const string MARKET_TIMEZONE = 'Europe/Paris';

    public function testARestartOutsideTheWindowPushesNothingBeforeTheReopening(): void
    {
        $scheduleState = new ArrayAdapter();
        $clock = new MockClock('2026-08-07 16:50:00', self::MARKET_TIMEZONE);

        $runningConsumer = self::createMessageGenerator($scheduleState, $clock);
        self::assertSame([], self::trackerMessagesOf($runningConsumer));

        $clock->modify('2026-08-07 17:05:00');
        self::assertCount(1, self::trackerMessagesOf($runningConsumer), 'the windowed group runs while the market is open');

        $clock->modify('2026-08-10 08:00:00');
        $restartedConsumer = self::createMessageGenerator($scheduleState, $clock);

        self::assertSame([], self::trackerMessagesOf($restartedConsumer), 'the runs missed on Friday are absorbed, nothing is pushed while the market is closed');

        $clock->modify('2026-08-10 09:00:00');
        self::assertSame([], self::trackerMessagesOf($restartedConsumer), 'the reopening alone pushes nothing, the group waits for its first cycle');

        $clock->modify('2026-08-10 09:16:00');
        self::assertCount(1, self::trackerMessagesOf($restartedConsumer), 'the cycle resumes on the first run following the reopening');
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
        return SyncsConfigLoaderFactory::messageGeneratorForFixture(self::FIXTURE_FILE, $scheduleState, $clock);
    }
}
