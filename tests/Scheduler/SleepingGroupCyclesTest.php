<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Message\SyncTrackerMessage;
use App\Message\SyncWeatherMessage;
use App\Schedule;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * What the schedule does around a night the device spends with its panel off: the cycles falling in
 * the dark are dropped rather than replayed, and the one push that survives lands on the instant the
 * panel comes back on, whether the consumer stayed up all night or was recycled during it.
 */
final class SleepingGroupCyclesTest extends TestCase
{
    private const string SLEEPING_CONFIG_FILE = 'syncs-with-sleep.yaml';
    private const string SLEEPING_WINDOWED_CONFIG_FILE = 'syncs-sleep-and-active-window.yaml';
    private const string SCHEDULE_NAME = 'default';
    private const string DEVICE_TIMEZONE = 'Europe/Paris';
    private const string EVENING_BEFORE_THE_NIGHT = '2026-08-04 23:50:00';
    private const string WAKE_UP = '2026-08-05 07:00:00';

    public function testNoCycleIsDispatchedWhileThePanelIsOff(): void
    {
        $clock = new MockClock(self::EVENING_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        $runningConsumer = self::createMessageGenerator(self::SLEEPING_CONFIG_FILE, new ArrayAdapter(), $clock);

        self::assertSame([], self::groupRunsOf($runningConsumer));

        foreach (['2026-08-05 01:00:00', '2026-08-05 03:00:00', '2026-08-05 06:59:00'] as $instantInTheDark) {
            $clock->modify($instantInTheDark);

            self::assertSame([], self::groupRunsOf($runningConsumer), 'nothing is pushed at '.$instantInTheDark);
        }
    }

    public function testEveryEnabledGroupPushesAtTheInstantThePanelComesBackOn(): void
    {
        $clock = new MockClock(self::EVENING_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        $runningConsumer = self::createMessageGenerator(self::SLEEPING_CONFIG_FILE, new ArrayAdapter(), $clock);
        self::groupRunsOf($runningConsumer);

        $clock->modify(self::WAKE_UP);

        self::assertSame(
            [[SyncWeatherMessage::class, self::WAKE_UP]],
            self::groupRunsOf($runningConsumer),
            'the group pushes at the wake-up rather than at its next cycle',
        );
    }

    public function testAConsumerRestartedInTheNightStillPushesAtTheWakeUp(): void
    {
        $scheduleState = new ArrayAdapter();
        $clock = new MockClock(self::EVENING_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        self::groupRunsOf(self::createMessageGenerator(self::SLEEPING_CONFIG_FILE, $scheduleState, $clock));

        $clock->modify('2026-08-05 03:00:00');
        $restartedConsumer = self::createMessageGenerator(self::SLEEPING_CONFIG_FILE, $scheduleState, $clock);
        self::assertSame([], self::groupRunsOf($restartedConsumer));

        $clock->modify(self::WAKE_UP);
        self::assertSame([[SyncWeatherMessage::class, self::WAKE_UP]], self::groupRunsOf($restartedConsumer));
    }

    public function testAConsumerRestartedAfterTheWakeUpPushesItOnceAndOnlyOnce(): void
    {
        $scheduleState = new ArrayAdapter();
        $clock = new MockClock(self::EVENING_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        self::groupRunsOf(self::createMessageGenerator(self::SLEEPING_CONFIG_FILE, $scheduleState, $clock));

        $clock->modify('2026-08-05 07:10:00');
        $lateConsumer = self::createMessageGenerator(self::SLEEPING_CONFIG_FILE, $scheduleState, $clock);
        self::assertSame(
            [[SyncWeatherMessage::class, self::WAKE_UP]],
            self::groupRunsOf($lateConsumer),
            'the wake-up run is delivered late rather than lost',
        );

        $clock->modify('2026-08-05 07:15:00');
        $consumerAfterIt = self::createMessageGenerator(self::SLEEPING_CONFIG_FILE, $scheduleState, $clock);
        self::assertSame(
            [],
            self::groupRunsOf($consumerAfterIt),
            'the run already delivered is not replayed by the next restart',
        );
    }

    public function testAGroupHeldToItsWindowDoesNotPushAtAWakeUpOutsideIt(): void
    {
        $clock = new MockClock(self::EVENING_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        $runningConsumer = self::createMessageGenerator(self::SLEEPING_WINDOWED_CONFIG_FILE, new ArrayAdapter(), $clock);
        self::groupRunsOf($runningConsumer);

        $clock->modify(self::WAKE_UP);
        self::assertSame(
            [[SyncWeatherMessage::class, self::WAKE_UP]],
            self::groupRunsOf($runningConsumer),
            'the market is still closed when the panel comes back on, only the group without a window pushes',
        );

        $clock->modify('2026-08-05 09:05:00');
        self::assertContains(
            [SyncTrackerMessage::class, '2026-08-05 09:05:00'],
            self::groupRunsOf($runningConsumer),
            'the windowed group resumes on its first cycle inside its own hours',
        );
    }

    /**
     * The sync group of every dispatched run, next to the instant it was triggered at.
     *
     * @return list<array{class-string, string}>
     */
    private static function groupRunsOf(MessageGenerator $messageGenerator): array
    {
        $groupRuns = [];

        foreach ($messageGenerator->getMessages() as $context => $message) {
            $groupRuns[] = [$message::class, $context->triggeredAt->setTimezone(new \DateTimeZone(self::DEVICE_TIMEZONE))->format('Y-m-d H:i:s')];
        }

        return $groupRuns;
    }

    private static function createMessageGenerator(string $configFileName, CacheInterface $scheduleState, ClockInterface $clock): MessageGenerator
    {
        $schedule = new Schedule(
            $scheduleState,
            SyncsConfigLoaderFactory::forConfigFile(\dirname(__DIR__).'/Config/Fixtures/'.$configFileName),
        );

        return new MessageGenerator($schedule, self::SCHEDULE_NAME, $clock);
    }
}
