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

        $entry = [
            'urgent' => $urgent,
            ...$payload,
            'id' => $id,
            'enqueuedAt' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
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

    public function domainKey(): string
    {
        return 'notifications';
    }
}
