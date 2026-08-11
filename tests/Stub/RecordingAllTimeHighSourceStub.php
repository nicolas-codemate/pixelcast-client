<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Config\Sync\TrackerItem;
use App\Tracker\AllTimeHigh;
use App\Tracker\History\AllTimeHighSourceInterface;

final class RecordingAllTimeHighSourceStub implements AllTimeHighSourceInterface
{
    /**
     * @var list<TrackerItem>
     */
    public array $itemsAskedFor = [];

    public function __construct(
        private readonly string $syncType,
        private readonly ?float $servedPrice = null,
        private readonly ?\DateTimeImmutable $servedReachedAt = null,
    ) {
    }

    public function syncType(): string
    {
        return $this->syncType;
    }

    public function fetchAllTimeHigh(TrackerItem $item, \DateTimeImmutable $observedAt): ?AllTimeHigh
    {
        $this->itemsAskedFor[] = $item;

        if (null === $this->servedPrice) {
            return null;
        }

        return new AllTimeHigh(
            $this->syncType,
            $item->symbol,
            $item->currency,
            $this->servedPrice,
            $this->servedReachedAt,
            $observedAt,
        );
    }
}
