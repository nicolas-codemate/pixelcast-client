<?php

declare(strict_types=1);

namespace App\Health;

use App\Config\Exception\PixelCastConfigException;
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
        $freshnessPerSyncGroup = [];

        foreach ($this->configLoader->load()->enabledSyncGroups() as $syncType => $syncGroup) {
            $activity = $syncGroup->activityAt($now);

            $freshnessPerSyncGroup[] = new SyncGroupFreshness(
                $syncType,
                $this->lastSuccessfulSyncStore->ageInSecondsOf($syncType),
                $syncGroup->interval->toleratedSilenceInSeconds(),
                $activity->isActive,
                $activity->secondsSinceBecameActive,
            );
        }

        return $freshnessPerSyncGroup;
    }
}
