<?php

declare(strict_types=1);

namespace App\Simulator\State;

abstract class AbstractPushedPayloadState implements ResettableState
{
    /** @var array<string, array<string, mixed>> */
    private array $payloadsByName = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $pushedAtByName = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(string $name, array $payload): void
    {
        $this->payloadsByName[$name] = $payload;
        $this->pushedAtByName[$name] = new \DateTimeImmutable();
    }

    public function delete(string $name): bool
    {
        if (!\array_key_exists($name, $this->payloadsByName)) {
            return false;
        }

        unset($this->payloadsByName[$name], $this->pushedAtByName[$name]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->payloadsByName[$name] ?? null;
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
        return $this->payloadsByName;
    }

    public function count(): int
    {
        return \count($this->payloadsByName);
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->payloadsByName);
    }

    public function reset(): void
    {
        $this->payloadsByName = [];
        $this->pushedAtByName = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            $this->payloadKey() => $this->payloadsByName,
            'count' => $this->count(),
            $this->pushedAtKey() => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return [
            $this->payloadKey() => $this->payloadsByName,
            $this->pushedAtKey() => PersistedStateReader::atomStringMap($this->pushedAtByName),
        ];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $this->payloadsByName = PersistedStateReader::payloadMap($persistedState[$this->payloadKey()] ?? null);
        $this->pushedAtByName = PersistedStateReader::atomDateMap($persistedState[$this->pushedAtKey()] ?? null);
    }

    public function domainKey(): string
    {
        return $this->payloadKey();
    }

    abstract protected function payloadKey(): string;

    private function pushedAtKey(): string
    {
        return $this->payloadKey().'PushedAt';
    }
}
