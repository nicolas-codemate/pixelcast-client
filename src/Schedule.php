<?php

declare(strict_types=1);

namespace App;

use App\Config\Sleep\SleepSchedule;
use App\Config\Sync\ActiveWindow;
use App\Config\Sync\SyncGroupConfig;
use App\Config\SyncsConfigLoader;
use App\Message\ApplyBrightnessMessage;
use App\Message\SyncMessage;
use App\Scheduler\ActiveWindowTrigger;
use App\Scheduler\BrightnessScheduleTrigger;
use App\Scheduler\SleepScheduleTrigger;
use App\Scheduler\SyncMessageRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface, SyncMessageRegistry
{
    public function __construct(
        private CacheInterface $cache,
        private SyncsConfigLoader $configLoader,
        private ClockInterface $clock,
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
                new PeriodicalTrigger($syncGroup->interval->expression),
                $syncGroup->syncMessage(),
                $sleepSchedule,
                $syncGroup->activeWindow,
            ));
        }

        // The brightness runs belong to no sync group, so they carry no active window: only the
        // sleep schedule holds them back, which keeps them silent while the panel is off.
        $brightnessSchedule = $config->brightnessSchedule();
        if (null !== $brightnessSchedule) {
            $schedule->add(self::recurringMessageOf(
                new BrightnessScheduleTrigger($brightnessSchedule, $this->clock),
                new ApplyBrightnessMessage(),
                $sleepSchedule,
            ));
        }

        return $schedule;
    }

    private static function recurringMessageOf(TriggerInterface $trigger, object $message, ?SleepSchedule $sleepSchedule, ?ActiveWindow $activeWindow = null): RecurringMessage
    {
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
