<?php

declare(strict_types=1);

namespace App;

use App\Config\Device\BrightnessSchedule;
use App\Config\Sleep\SleepSchedule;
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
            $schedule->add(self::recurringMessageOf($syncGroup, $sleepSchedule));
        }

        if (null !== $config->device?->brightnessSchedule) {
            $schedule->add(self::brightnessRecurringMessage($sleepSchedule));
        }

        return $schedule;
    }

    /**
     * The brightness tick belongs to no sync group, so it carries no active window: only the sleep
     * schedule holds it back, which keeps it silent while the panel is off.
     */
    private static function brightnessRecurringMessage(?SleepSchedule $sleepSchedule): RecurringMessage
    {
        $applyBrightnessMessage = new ApplyBrightnessMessage();
        $recurringMessage = RecurringMessage::every(BrightnessSchedule::TICK_INTERVAL, $applyBrightnessMessage);

        if (null === $sleepSchedule) {
            return $recurringMessage;
        }

        return RecurringMessage::trigger(
            new SleepScheduleTrigger($recurringMessage->getTrigger(), $sleepSchedule),
            $applyBrightnessMessage,
        );
    }

    private static function recurringMessageOf(SyncGroupConfig $syncGroup, ?SleepSchedule $sleepSchedule): RecurringMessage
    {
        $syncMessage = $syncGroup->syncMessage();
        $recurringMessage = RecurringMessage::every($syncGroup->interval->expression, $syncMessage);

        if (null === $sleepSchedule && null === $syncGroup->activeWindow) {
            return $recurringMessage;
        }

        $trigger = $recurringMessage->getTrigger();

        // The sleep schedule sits under the active window: the run date it moves to the wake-up is
        // still held to the hours of the group, which may well be closed when the panel relights.
        if (null !== $sleepSchedule) {
            $trigger = new SleepScheduleTrigger($trigger, $sleepSchedule);
        }

        if (null !== $syncGroup->activeWindow) {
            $trigger = new ActiveWindowTrigger($trigger, $syncGroup->activeWindow);
        }

        return RecurringMessage::trigger($trigger, $syncMessage);
    }
}
