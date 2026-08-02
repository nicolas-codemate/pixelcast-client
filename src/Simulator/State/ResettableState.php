<?php

declare(strict_types=1);

namespace App\Simulator\State;

interface ResettableState
{
    public function reset(): void;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array;

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array;

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void;

    public function domainKey(): string;
}
