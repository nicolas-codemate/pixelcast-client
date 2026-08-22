<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class TrackerState implements ResettableState
{
    /** @var array<string, array<string, mixed>> */
    private array $trackers = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $pushedAtByName = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(string $name, array $payload): void
    {
        $this->trackers[$name] = $payload;
        $this->pushedAtByName[$name] = new \DateTimeImmutable();
    }

    public function delete(string $name): bool
    {
        if (!\array_key_exists($name, $this->trackers)) {
            return false;
        }

        unset($this->trackers[$name], $this->pushedAtByName[$name]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->trackers[$name] ?? null;
    }

    public function pushedAt(string $name): ?\DateTimeImmutable
    {
        return $this->pushedAtByName[$name] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->trackers;
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->trackers);
    }

    public function reset(): void
    {
        $this->trackers = [];
        $this->pushedAtByName = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'trackers' => $this->trackers,
            'count' => \count($this->trackers),
            'trackersPushedAt' => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return [
            'trackers' => $this->trackers,
            'trackersPushedAt' => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $this->trackers = PersistedStateReader::payloadMap($persistedState['trackers'] ?? null);
        $this->pushedAtByName = PersistedStateReader::atomDateMap($persistedState['trackersPushedAt'] ?? null);
    }

    public function domainKey(): string
    {
        return 'trackers';
    }
}
