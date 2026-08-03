<?php

declare(strict_types=1);

namespace App;

use App\Config\Sync\SyncGroupConfig;
use App\Config\SyncsConfigLoader;
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
            $schedule->add(RecurringMessage::every($syncGroup->interval->expression, $syncGroup->syncMessage()));
        }

        return $schedule;
    }
}
