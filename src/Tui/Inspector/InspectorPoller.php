<?php

declare(strict_types=1);

namespace App\Tui\Inspector;

final class InspectorPoller
{
    private ?InspectorSnapshot $latestSnapshot = null;
    private float $secondsSinceLastPoll = 0.0;

    public function __construct(
        private readonly InspectorTransport $httpClient,
        private readonly ?string $baseUrl,
        private readonly float $pollIntervalSeconds = 1.0,
    ) {
    }

    public function poll(): InspectorSnapshot
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

    public function getLatestSnapshot(): ?InspectorSnapshot
    {
        return $this->latestSnapshot;
    }
}
