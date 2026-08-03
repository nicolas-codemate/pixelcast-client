<?php

declare(strict_types=1);

namespace App\Health;

use App\Config\Exception\PixelCastConfigException;
use App\Config\SyncsConfigLoader;

final readonly class SyncHealthChecker
{
    // A cycle may be missed twice before the failure is called lasting: the forecast cache expires
    // before a cycle elapses and the device client does not retry, so each cycle is a fresh attempt,
    // and the hourly process recycle replays the run it missed.
    private const int ALLOWED_MISSED_CYCLES = 2;

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
                $syncGroup->interval->lengthInSeconds * (self::ALLOWED_MISSED_CYCLES + 1),
            );
        }

        return $freshnessPerSyncGroup;
    }
}
