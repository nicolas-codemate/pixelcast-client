<?php

declare(strict_types=1);

namespace App\Tui\DeviceStatus;

final class StatsPoller
{
    private ?StatsSnapshot $latestSnapshot = null;
    private float $secondsSinceLastPoll = 0.0;

    public function __construct(
        private readonly StatsTransport $httpClient,
        private readonly ?string $baseUrl,
        private readonly float $pollIntervalSeconds = 2.0,
    ) {
    }

    public function poll(): StatsSnapshot
    {
        $snapshot = $this->httpClient->fetch($this->baseUrl);
        $this->latestSnapshot = $snapshot;
        $this->secondsSinceLastPoll = 0.0;

        return $snapshot;
    }

    public function pollIfDue(float $deltaSeconds): bool
    {
        $clampedDelta = max(0.0, $deltaSeconds);
        $this->secondsSinceLastPoll += $clampedDelta;

        if ($this->secondsSinceLastPoll < $this->pollIntervalSeconds) {
            return false;
        }

        $this->poll();

        return true;
    }

    public function getLatestSnapshot(): ?StatsSnapshot
    {
        return $this->latestSnapshot;
    }
}
