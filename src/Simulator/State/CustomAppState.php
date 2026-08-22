<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class CustomAppState implements ResettableState
{
    /** @var array<string, array<string, mixed>> */
    private array $apps = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $pushedAtByName = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(string $name, array $payload): void
    {
        $this->apps[$name] = $payload;
        $this->pushedAtByName[$name] = new \DateTimeImmutable();
    }

    public function delete(string $name): bool
    {
        if (!\array_key_exists($name, $this->apps)) {
            return false;
        }

        unset($this->apps[$name], $this->pushedAtByName[$name]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->apps[$name] ?? null;
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
        return $this->apps;
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->apps);
    }

    public function reset(): void
    {
        $this->apps = [];
        $this->pushedAtByName = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'apps' => $this->apps,
            'count' => \count($this->apps),
            'appsPushedAt' => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return [
            'apps' => $this->apps,
            'appsPushedAt' => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $this->apps = PersistedStateReader::payloadMap($persistedState['apps'] ?? null);
        $this->pushedAtByName = PersistedStateReader::atomDateMap($persistedState['appsPushedAt'] ?? null);
    }

    public function domainKey(): string
    {
        return 'customApps';
    }
}
