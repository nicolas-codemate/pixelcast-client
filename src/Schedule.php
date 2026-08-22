<?php

declare(strict_types=1);

namespace App;

use App\Config\Device\BrightnessSchedule;
use App\Config\Sleep\SleepSchedule;
use App\Config\Sync\ActiveWindow;
use App\Config\Sync\SyncGroupConfig;
use App\Config\SyncsConfigLoader;
use App\Message\ApplyBrightnessMessage;
use App\Message\SyncMessage;
use App\Scheduler\ActiveWindowTrigger;
use App\Scheduler\SleepScheduleTrigger;
use App\Scheduler\SyncMessageRegistry;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface, SyncMessageRegistry
{
    public function __construct(
        private CacheInterface $cache,
        private SyncsConfigLoader $configLoader,
    ) {
    }

    public function syncMessages(): array
    {
        return array_map(
            static fn (SyncGroupConfig $syncGroup): SyncMessage => $syncGroup->syncMessage(),
            $this->configLoader->load()->enabledSyncGroups(),
        );
    }

    public function getSchedule(): SymfonySchedule
    {
        $config = $this->configLoader->load();
        $sleepSchedule = $config->sleepSchedule();

        $schedule = new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach ($config->enabledSyncGroups() as $syncGroup) {
            $schedule->add(self::recurringMessageOf(
                $syncGroup->interval->expression,
                $syncGroup->syncMessage(),
                $sleepSchedule,
                $syncGroup->activeWindow,
            ));
        }

        // The brightness tick belongs to no sync group, so it carries no active window: only the
        // sleep schedule holds it back, which keeps it silent while the panel is off.
        if (null !== $config->brightnessSchedule()) {
            $schedule->add(self::recurringMessageOf(
                BrightnessSchedule::TICK_INTERVAL,
                new ApplyBrightnessMessage(),
                $sleepSchedule,
            ));
        }

        return $schedule;
    }

    private static function recurringMessageOf(string $interval, object $message, ?SleepSchedule $sleepSchedule, ?ActiveWindow $activeWindow = null): RecurringMessage
    {
        $recurringMessage = RecurringMessage::every($interval, $message);

        if (null === $sleepSchedule && null === $activeWindow) {
            return $recurringMessage;
        }

        $trigger = $recurringMessage->getTrigger();

        // The sleep schedule sits under the active window: the run date it moves to the wake-up is
        // still held to the hours of the group, which may well be closed when the panel relights.
        if (null !== $sleepSchedule) {
            $trigger = new SleepScheduleTrigger($trigger, $sleepSchedule);
        }

        if (null !== $activeWindow) {
            $trigger = new ActiveWindowTrigger($trigger, $activeWindow);
        }

        return RecurringMessage::trigger($trigger, $message);
    }
}
