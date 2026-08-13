<?php

declare(strict_types=1);

namespace App\Health;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncGroupActivity;
use App\Config\SyncsConfigLoader;
use Psr\Clock\ClockInterface;

final readonly class SyncHealthChecker
{
    public function __construct(
        private SyncsConfigLoader $configLoader,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<SyncGroupFreshness>
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function checkEnabledSyncGroups(): array
    {
        $now = $this->clock->now();
        $config = $this->configLoader->load();
        $sleepActivity = $config->sleepSchedule()?->activityAt($now) ?? SyncGroupActivity::alwaysActive();
        $freshnessPerSyncGroup = [];

        foreach ($config->enabledSyncGroups() as $syncType => $syncGroup) {
            $activity = $syncGroup->activityAt($now)->combinedWith($sleepActivity);

            $freshnessPerSyncGroup[] = new SyncGroupFreshness(
                $syncType,
                $this->lastSuccessfulSyncStore->ageInSecondsOf($syncType),
                $syncGroup->interval->toleratedSilenceInSeconds(),
                $activity->isActive,
                $activity->secondsSinceBecameActive,
                !$sleepActivity->isActive,
            );
        }

        return $freshnessPerSyncGroup;
    }
}
