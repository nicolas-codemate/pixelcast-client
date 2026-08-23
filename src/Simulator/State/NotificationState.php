<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class NotificationState implements ResettableState
{
    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(array $payload, bool $urgent = false): string
    {
        $id = isset($payload['id']) && \is_string($payload['id']) && '' !== $payload['id']
            ? $payload['id']
            : 'notif_'.bin2hex(random_bytes(6));

        $landsAtHeadOfQueue = $urgent || [] === $this->queue;

        $entry = [
            'urgent' => $urgent,
            ...$payload,
            'id' => $id,
            'displayed' => $landsAtHeadOfQueue,
        ];

        if ($urgent) {
            array_unshift($this->queue, $entry);
        } else {
            $this->queue[] = $entry;
        }

        return $id;
    }

    public function dismissCurrent(): bool
    {
        if ([] === $this->queue) {
            return false;
        }

        array_shift($this->queue);
        $this->markHeadOfQueueAsDisplayed();

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return $this->queue[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->queue;
    }

    public function currentIndex(): int
    {
        return [] === $this->queue ? -1 : 0;
    }

    public function count(): int
    {
        return \count($this->queue);
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'queue' => $this->queue,
            'count' => \count($this->queue),
            'current' => $this->current(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return ['queue' => $this->queue];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $this->queue = PersistedStateReader::payloadList($persistedState['queue'] ?? null);
        $this->markHeadOfQueueAsDisplayed();
    }

    public function domainKey(): string
    {
        return 'notifications';
    }

    /**
     * The head of the queue is what the device has on screen, so reaching that position is what
     * makes an entry displayed - and it stays displayed once an urgent notification pushes it back.
     */
    private function markHeadOfQueueAsDisplayed(): void
    {
        if ([] === $this->queue) {
            return;
        }

        $this->queue[0]['displayed'] = true;
    }
}
