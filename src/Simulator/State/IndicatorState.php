<?php

declare(strict_types=1);

namespace App\Simulator\State;

use App\Client\Indicator\IndicatorMode;

final class IndicatorState implements ResettableState
{
    private const array VALID_SLOTS = [1, 2, 3];

    /** @var array<int, array<string, mixed>|null> */
    private array $slots = [1 => null, 2 => null, 3 => null];

    /** @var array<int, mixed> */
    private array $colorsKeptPerSlot = [1 => null, 2 => null, 3 => null];

    /**
     * @param array<string, mixed> $payload
     */
    public function set(int $slot, array $payload): void
    {
        $this->assertSlot($slot);
        $this->slots[$slot] = $this->withTheColorTheSlotKeeps($slot, $payload);
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
        $this->colorsKeptPerSlot = [1 => null, 2 => null, 3 => null];
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

    /**
     * @return array<string, mixed>
     */
    public function exportForPersistence(): array
    {
        return ['slots' => $this->slots, 'colorsKeptPerSlot' => $this->colorsKeptPerSlot];
    }

    /**
     * @param array<string, mixed> $persistedState
     */
    public function restoreFromPersistence(array $persistedState): void
    {
        $this->reset();

        foreach (PersistedStateReader::payloadMap($persistedState['slots'] ?? null) as $persistedSlot => $payload) {
            $slot = (int) $persistedSlot;

            if (\in_array($slot, self::VALID_SLOTS, true)) {
                $this->slots[$slot] = $payload;
            }
        }

        $persistedColors = $persistedState['colorsKeptPerSlot'] ?? null;
        if (!\is_array($persistedColors)) {
            return;
        }

        foreach ($persistedColors as $persistedSlot => $keptColor) {
            $slot = (int) $persistedSlot;

            if (\in_array($slot, self::VALID_SLOTS, true) && null !== $keptColor) {
                $this->colorsKeptPerSlot[$slot] = $keptColor;
            }
        }
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

    /**
     * The device keeps one color per slot: lighting a slot without naming a color lights it in the
     * color that slot last carried, and turning it off does not forget that color.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withTheColorTheSlotKeeps(int $slot, array $payload): array
    {
        $pushedColor = $payload['color'] ?? null;
        if (null !== $pushedColor) {
            $this->colorsKeptPerSlot[$slot] = $pushedColor;

            return $payload;
        }

        $keptColor = $this->colorsKeptPerSlot[$slot];
        if (null === $keptColor || !self::lightsTheIndicator($payload)) {
            return $payload;
        }

        $payload['color'] = $keptColor;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function lightsTheIndicator(array $payload): bool
    {
        $pushedMode = $payload['mode'] ?? null;

        if (!\is_string($pushedMode)) {
            return false;
        }

        return IndicatorMode::tryFrom($pushedMode)?->lightsTheIndicator() ?? false;
    }
}
