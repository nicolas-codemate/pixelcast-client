<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Config\Device\BrightnessSchedule;
use App\Message\ApplyBrightnessMessage;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * What the brightness tick does around a night the panel spends off: nothing is pushed into the
 * dark, the one tick that survives lands on the instant the panel comes back on, and the level it
 * carries is the one of the window covering that morning rather than the evening dimming.
 *
 * The fixture holds the panel at 120 from 07:00 to 22:00, dims it to 20 from 22:00 to 07:00, and
 * sleeps from 00:00 to 07:00, so the evening window covers the whole night of sleep.
 */
final class SleepingBrightnessCyclesTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string CONFIG_FILE = 'syncs-brightness-and-sleep.yaml';
    private const string DEVICE_TIMEZONE = 'Europe/Paris';
    private const string LAST_MINUTE_BEFORE_THE_NIGHT = '2026-08-04 23:59:00';
    private const string WAKE_UP = '2026-08-05 07:00:00';
    private const int EVENING_LEVEL = 20;
    private const int DAY_LEVEL = 120;

    public function testNoLevelIsPushedWhileThePanelIsOff(): void
    {
        $clock = new MockClock(self::LAST_MINUTE_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        $runningConsumer = self::createMessageGenerator(new ArrayAdapter(), $clock);

        self::assertSame([], self::brightnessRunsOf($runningConsumer));

        foreach (['2026-08-05 01:00:00', '2026-08-05 03:00:00', '2026-08-05 06:59:00'] as $instantInTheDark) {
            $clock->modify($instantInTheDark);

            self::assertSame([], self::brightnessRunsOf($runningConsumer), 'nothing is pushed at '.$instantInTheDark);
        }
    }

    public function testTheTickComesBackAtTheInstantThePanelDoes(): void
    {
        $clock = new MockClock(self::LAST_MINUTE_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        $runningConsumer = self::createMessageGenerator(new ArrayAdapter(), $clock);
        self::brightnessRunsOf($runningConsumer);

        $clock->modify(self::WAKE_UP);

        self::assertSame(
            [self::WAKE_UP],
            self::brightnessRunsOf($runningConsumer),
            'the tick is moved to the wake-up rather than replayed for every minute of the night',
        );
    }

    public function testAConsumerRestartedInTheNightStillTicksAtTheWakeUp(): void
    {
        $scheduleState = new ArrayAdapter();
        $clock = new MockClock(self::LAST_MINUTE_BEFORE_THE_NIGHT, self::DEVICE_TIMEZONE);
        self::brightnessRunsOf(self::createMessageGenerator($scheduleState, $clock));

        $clock->modify('2026-08-05 03:00:00');
        $restartedConsumer = self::createMessageGenerator($scheduleState, $clock);
        self::assertSame([], self::brightnessRunsOf($restartedConsumer));

        $clock->modify(self::WAKE_UP);
        self::assertSame([self::WAKE_UP], self::brightnessRunsOf($restartedConsumer));
    }

    public function testTheWakeUpCarriesTheLevelOfTheMorningAndNotTheEveningDimming(): void
    {
        $brightnessSchedule = self::brightnessScheduleOfTheFixture();

        self::assertSame(self::EVENING_LEVEL, $brightnessSchedule->levelAt(self::instantAt(self::LAST_MINUTE_BEFORE_THE_NIGHT))->level);
        self::assertSame(
            self::DAY_LEVEL,
            $brightnessSchedule->levelAt(self::instantAt(self::WAKE_UP))->level,
            'the panel comes back on the window covering the wake-up, not on the level it was dimmed to before going off',
        );
    }

    /**
     * The instant every brightness tick was triggered at, the sync groups of the fixture left aside.
     *
     * @return list<string>
     */
    private static function brightnessRunsOf(MessageGenerator $messageGenerator): array
    {
        $brightnessRuns = [];

        foreach ($messageGenerator->getMessages() as $context => $message) {
            if ($message instanceof ApplyBrightnessMessage) {
                $brightnessRuns[] = $context->triggeredAt->setTimezone(new \DateTimeZone(self::DEVICE_TIMEZONE))->format('Y-m-d H:i:s');
            }
        }

        return $brightnessRuns;
    }

    private static function brightnessScheduleOfTheFixture(): BrightnessSchedule
    {
        $brightnessSchedule = SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.self::CONFIG_FILE)->load()->device?->brightnessSchedule;

        self::assertNotNull($brightnessSchedule);

        return $brightnessSchedule;
    }

    private static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant, new \DateTimeZone(self::DEVICE_TIMEZONE));
    }

    private static function createMessageGenerator(CacheInterface $scheduleState, ClockInterface $clock): MessageGenerator
    {
        return SyncsConfigLoaderFactory::messageGeneratorForFixture(self::CONFIG_FILE, $scheduleState, $clock);
    }
}
