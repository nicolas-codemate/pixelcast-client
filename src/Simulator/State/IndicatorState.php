<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class IndicatorState implements ResettableState
{
    private const array VALID_SLOTS = [1, 2, 3];

    /** @var array<int, array<string, mixed>|null> */
    private array $slots = [1 => null, 2 => null, 3 => null];

    /**
     * @param array<string, mixed> $payload
     */
    public function set(int $slot, array $payload): void
    {
        $this->assertSlot($slot);
        $this->slots[$slot] = $payload;
    }

    public function clear(int $slot): void
    {
        $this->assertSlot($slot);
        $this->slots[$slot] = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $slot): ?array
    {
        $this->assertSlot($slot);

        return $this->slots[$slot];
    }

    public function reset(): void
    {
        $this->slots = [1 => null, 2 => null, 3 => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'slot1' => $this->slots[1],
            'slot2' => $this->slots[2],
            'slot3' => $this->slots[3],
        ];
    }

    public function domainKey(): string
    {
        return 'indicators';
    }

    private function assertSlot(int $slot): void
    {
        if (!\in_array($slot, self::VALID_SLOTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Indicator slot must be 1, 2 or 3, got %d.', $slot));
        }
    }
}
