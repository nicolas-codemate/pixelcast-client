<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\Tracker\TrackerPayload;
use App\Provider\Tracker\TrackerProviderInterface;

final class StaticTrackerProviderStub implements TrackerProviderInterface
{
    /**
     * @var list<\DateTimeImmutable|null> the instant of every call, null standing for a fetch of every item
     */
    public array $activeWindowInstants = [];

    /**
     * @param list<TrackerPayload> $trackerPayloads
     */
    public function __construct(
        private readonly string $syncType,
        private readonly array $trackerPayloads = [],
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function syncType(): string
    {
        return $this->syncType;
    }

    public function fetchTrackers(?\DateTimeImmutable $activeWindowInstant): array
    {
        $this->activeWindowInstants[] = $activeWindowInstant;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->trackerPayloads;
    }
}
