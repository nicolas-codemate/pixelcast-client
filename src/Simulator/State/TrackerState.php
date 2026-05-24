<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class TrackerState implements ResettableState
{
    /** @var array<string, array<string, mixed>> */
    private array $trackers = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(string $name, array $payload): void
    {
        $this->trackers[$name] = $payload;
    }

    public function delete(string $name): bool
    {
        if (!\array_key_exists($name, $this->trackers)) {
            return false;
        }

        unset($this->trackers[$name]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->trackers[$name] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_values($this->trackers);
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->trackers);
    }

    public function reset(): void
    {
        $this->trackers = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'trackers' => $this->trackers,
            'count' => \count($this->trackers),
        ];
    }

    public function domainKey(): string
    {
        return 'trackers';
    }
}
