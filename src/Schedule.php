<?php

declare(strict_types=1);

namespace App;

use App\Config\Sync\SyncGroupConfig;
use App\Config\SyncsConfigLoader;
use App\Scheduler\ActiveWindowTrigger;
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
            static fn (SyncGroupConfig $syncGroup): object => $syncGroup->syncMessage(),
            $this->configLoader->load()->enabledSyncGroups(),
        );
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach ($this->configLoader->load()->enabledSyncGroups() as $syncGroup) {
            $schedule->add(self::recurringMessageOf($syncGroup));
        }

        return $schedule;
    }

    private static function recurringMessageOf(SyncGroupConfig $syncGroup): RecurringMessage
    {
        $syncMessage = $syncGroup->syncMessage();
        $recurringMessage = RecurringMessage::every($syncGroup->interval->expression, $syncMessage);

        if (null === $syncGroup->activeWindow) {
            return $recurringMessage;
        }

        return RecurringMessage::trigger(
            new ActiveWindowTrigger($recurringMessage->getTrigger(), $syncGroup->activeWindow),
            $syncMessage,
        );
    }
}
