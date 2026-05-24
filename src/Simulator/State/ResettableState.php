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

    public function domainKey(): string;
}
