<?php

declare(strict_types=1);

namespace App\Health;

use App\Config\Exception\PixelCastConfigException;
use App\Config\SyncsConfigLoader;

final readonly class SyncHealthChecker
{
    public function __construct(
        private SyncsConfigLoader $configLoader,
        private LastSuccessfulSyncStore $lastSuccessfulSyncStore,
    ) {
    }

    /**
     * @return list<SyncGroupFreshness>
     *
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function checkEnabledSyncGroups(): array
    {
        $freshnessPerSyncGroup = [];

        foreach ($this->configLoader->load()->enabledSyncGroups() as $syncType => $syncGroup) {
            $freshnessPerSyncGroup[] = new SyncGroupFreshness(
                $syncType,
                $this->lastSuccessfulSyncStore->ageInSecondsOf($syncType),
                $syncGroup->interval->toleratedSilenceInSeconds(),
            );
        }

        return $freshnessPerSyncGroup;
    }
}
