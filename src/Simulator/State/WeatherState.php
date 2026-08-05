<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class WeatherState implements ResettableState
{
    /** @var array<string, mixed>|null */
    private ?array $current = null;

    /** @var list<array<string, mixed>> */
    private array $forecast = [];

    /** @var array<string, mixed>|null */
    private ?array $today = null;

    private ?\DateTimeImmutable $lastUpdatedAt = null;

    /**
     * @param array<string, mixed>|null $current
     * @param list<array<string, mixed>> $forecast
     * @param array<string, mixed>|null $today
     */
    public function update(?array $current, array $forecast, ?array $today): void
    {
        $this->current = $current;
        $this->forecast = $forecast;
        $this->today = $today;
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

    /**
     * @return array<string, mixed>|null
     */
    public function today(): ?array
    {
        return $this->today;
    }

    public function lastUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->lastUpdatedAt;
    }

    public function reset(): void
    {
        $this->current = null;
        $this->forecast = [];
        $this->today = null;
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
            'today' => $this->today,
            'lastUpdatedAt' => $this->lastUpdatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return [
            'current' => $this->current,
            'forecast' => $this->forecast,
            'today' => $this->today,
            'lastUpdatedAt' => $this->lastUpdatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $this->current = PersistedStateReader::payload($persistedState['current'] ?? null);
        $this->forecast = PersistedStateReader::payloadList($persistedState['forecast'] ?? null);
        $this->today = PersistedStateReader::payload($persistedState['today'] ?? null);
        $this->lastUpdatedAt = PersistedStateReader::atomDate($persistedState['lastUpdatedAt'] ?? null);
    }

    public function domainKey(): string
    {
        return 'weather';
    }
}
