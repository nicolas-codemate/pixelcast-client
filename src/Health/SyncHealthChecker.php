<?php

declare(strict_types=1);

namespace App\Health;

use App\Config\Exception\PixelCastConfigException;
use App\Config\SyncsConfigLoader;
use Psr\Clock\ClockInterface;

final readonly class SyncHealthChecker
{
    // A cycle may be missed twice before the failure is called lasting: the forecast cache expires
    // before a cycle elapses and the device client does not retry, so each cycle is a fresh attempt,
    // and the hourly process recycle replays the run it missed.
    private const int ALLOWED_MISSED_CYCLES = 2;

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
        $now = $this->clock->now()->getTimestamp();
        $freshnessPerSyncGroup = [];

        foreach ($this->configLoader->load()->enabledSyncGroups() as $syncType => $syncGroup) {
            $lastSuccessAt = $this->lastSuccessfulSyncStore->lastSuccessAt($syncType);

            $freshnessPerSyncGroup[] = new SyncGroupFreshness(
                $syncType,
                null === $lastSuccessAt ? null : $now - $lastSuccessAt->getTimestamp(),
                $syncGroup->interval->lengthInSeconds * (self::ALLOWED_MISSED_CYCLES + 1),
            );
        }

        return $freshnessPerSyncGroup;
    }
}
