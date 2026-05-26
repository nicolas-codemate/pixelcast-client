<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Renderer;

use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\TerminalSafeText;

final class IndicatorsRenderer implements DomainRenderer
{
    private const string NO_DATA_TEXT = 'no data';
    private const string EMPTY_SLOT_TEXT = '-';

    /** @var list<string> */
    private const array SLOT_KEYS = ['slot1', 'slot2', 'slot3'];

    public function render(DeviceDomainState $state): string
    {
        if (false === $state->hasData) {
            return self::NO_DATA_TEXT;
        }

        $payload = $state->payload;
        if (!\is_array($payload)) {
            return self::NO_DATA_TEXT;
        }

        $lines = [];
        foreach (self::SLOT_KEYS as $slotKey) {
            $lines[] = $slotKey.': '.$this->formatSlotLabel($payload[$slotKey] ?? null);
        }

        return implode("\n", $lines);
    }

    public function summary(DeviceDomainState $state): string
    {
        if (false === $state->hasData) {
            return '';
        }

        $payload = $state->payload;
        if (!\is_array($payload)) {
            return '';
        }

        $filled = 0;
        foreach (self::SLOT_KEYS as $slotKey) {
            if (self::EMPTY_SLOT_TEXT !== $this->formatSlotLabel($payload[$slotKey] ?? null)) {
                ++$filled;
            }
        }

        return $filled.'/'.\count(self::SLOT_KEYS).' slots';
    }

    private function formatSlotLabel(mixed $slot): string
    {
        if (null === $slot) {
            return self::EMPTY_SLOT_TEXT;
        }
        if (\is_string($slot)) {
            return '' === $slot ? self::EMPTY_SLOT_TEXT : TerminalSafeText::stripControlBytes($slot);
        }
        if (\is_array($slot)) {
            $label = $slot['label'] ?? $slot['name'] ?? null;
            if (\is_string($label) && '' !== $label) {
                return TerminalSafeText::stripControlBytes($label);
            }
        }

        return self::EMPTY_SLOT_TEXT;
    }
}
