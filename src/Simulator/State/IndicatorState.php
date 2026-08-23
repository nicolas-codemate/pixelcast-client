<?php

declare(strict_types=1);

namespace App\Simulator\State;

use App\Client\Indicator\IndicatorMode;
use App\Simulator\Projection\ColorProjection;

final class IndicatorState implements ResettableState
{
    private const array VALID_SLOTS = [1, 2, 3];
    private const array NOTHING_PER_SLOT = [1 => null, 2 => null, 3 => null];

    /** @var array<int, array<string, mixed>|null> */
    private array $slots = self::NOTHING_PER_SLOT;

    /** @var array<int, string|null> */
    private array $colorsKeptPerSlot = self::NOTHING_PER_SLOT;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the payload as applied, which carries the color the slot keeps
     */
    public function set(int $slot, array $payload): array
    {
        $this->assertSlot($slot);

        $appliedPayload = $this->withTheColorTheSlotKeeps($slot, $payload);
        $this->slots[$slot] = $appliedPayload;

        return $appliedPayload;
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
        $this->slots = self::NOTHING_PER_SLOT;
        $this->colorsKeptPerSlot = self::NOTHING_PER_SLOT;
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
            'colorsKeptPerSlot' => $this->colorsKeptPerSlot,
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
        if (\is_array($persistedColors)) {
            foreach (self::VALID_SLOTS as $slot) {
                $keptColor = $persistedColors[$slot] ?? null;
                $this->colorsKeptPerSlot[$slot] = \is_string($keptColor) ? $keptColor : null;
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
            $this->colorsKeptPerSlot[$slot] = ColorProjection::hexCodeOf($pushedColor);

            return $payload;
        }

        $pushedMode = $payload['mode'] ?? null;
        $lightsTheIndicator = \is_string($pushedMode) && true === IndicatorMode::tryFrom($pushedMode)?->lightsTheIndicator();

        $keptColor = $this->colorsKeptPerSlot[$slot];
        if ($lightsTheIndicator && null !== $keptColor) {
            $payload['color'] = $keptColor;
        }

        return $payload;
    }
}
