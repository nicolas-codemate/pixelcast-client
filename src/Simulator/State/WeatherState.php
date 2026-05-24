<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class WeatherState implements ResettableState
{
    /** @var array<string, mixed>|null */
    private ?array $current = null;

    /** @var list<array<string, mixed>> */
    private array $forecast = [];

    private ?\DateTimeImmutable $lastUpdatedAt = null;

    /**
     * @param array<string, mixed>|null $current
     * @param list<array<string, mixed>> $forecast
     */
    public function update(?array $current, array $forecast): void
    {
        $this->current = $current;
        $this->forecast = $forecast;
        $this->lastUpdatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return $this->current;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forecast(): array
    {
        return $this->forecast;
    }

    public function lastUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->lastUpdatedAt;
    }

    public function reset(): void
    {
        $this->current = null;
        $this->forecast = [];
        $this->lastUpdatedAt = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'valid' => null !== $this->current,
            'current' => $this->current,
            'forecast' => $this->forecast,
            'lastUpdatedAt' => $this->lastUpdatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    public function domainKey(): string
    {
        return 'weather';
    }
}
