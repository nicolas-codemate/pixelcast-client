<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class GaugeState implements ResettableState
{
    /** @var array<string, array<string, mixed>> */
    private array $gauges = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $pushedAtByName = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(string $name, array $payload): void
    {
        $this->gauges[$name] = $payload;
        $this->pushedAtByName[$name] = new \DateTimeImmutable();
    }

    public function delete(string $name): bool
    {
        if (!\array_key_exists($name, $this->gauges)) {
            return false;
        }

        unset($this->gauges[$name], $this->pushedAtByName[$name]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->gauges[$name] ?? null;
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
        return $this->gauges;
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->gauges);
    }

    public function reset(): void
    {
        $this->gauges = [];
        $this->pushedAtByName = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'gauges' => $this->gauges,
            'count' => \count($this->gauges),
            'gaugesPushedAt' => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return [
            'gauges' => $this->gauges,
            'gaugesPushedAt' => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $this->gauges = PersistedStateReader::payloadMap($persistedState['gauges'] ?? null);
        $this->pushedAtByName = PersistedStateReader::atomDateMap($persistedState['gaugesPushedAt'] ?? null);
    }

    public function domainKey(): string
    {
        return 'gauges';
    }
}
