<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class CustomAppState implements ResettableState
{
    /** @var array<string, array<string, mixed>> */
    private array $apps = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(string $name, array $payload): void
    {
        $this->apps[$name] = $payload;
    }

    public function delete(string $name): bool
    {
        if (!\array_key_exists($name, $this->apps)) {
            return false;
        }

        unset($this->apps[$name]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->apps[$name] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_values($this->apps);
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->apps);
    }

    public function reset(): void
    {
        $this->apps = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'apps' => $this->apps,
            'count' => \count($this->apps),
        ];
    }

    public function domainKey(): string
    {
        return 'customApps';
    }
}
