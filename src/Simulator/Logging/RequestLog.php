<?php

declare(strict_types=1);

namespace App\Simulator\Logging;

final class RequestLog
{
    private const int CAPACITY = 50;

    /** @var list<RequestLogEntry> */
    private array $entries = [];

    public function record(RequestLogEntry $entry): void
    {
        $this->entries[] = $entry;

        if (\count($this->entries) > self::CAPACITY) {
            array_shift($this->entries);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshotEntries(): array
    {
        return array_map(
            static fn (RequestLogEntry $entry): array => $entry->toArray(),
            $this->entries,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportForPersistence(): array
    {
        return $this->snapshotEntries();
    }

    /**
     * @param list<array<string, mixed>> $persistedEntries
     */
    public function restoreFromPersistence(array $persistedEntries): void
    {
        $restoredEntries = [];

        foreach ($persistedEntries as $persistedEntry) {
            try {
                $restoredEntries[] = RequestLogEntry::fromArray($persistedEntry);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        $this->entries = \array_slice($restoredEntries, -self::CAPACITY);
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
