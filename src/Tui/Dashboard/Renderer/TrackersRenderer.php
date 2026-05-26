<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Renderer;

use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\TerminalSafeText;

final class TrackersRenderer implements DomainRenderer
{
    private const string NO_DATA_TEXT = 'no data';
    private const int MAX_ROWS = 8;

    public function render(DeviceDomainState $state): string
    {
        if (false === $state->hasData) {
            return self::NO_DATA_TEXT;
        }

        $payload = $state->payload;
        if (!\is_array($payload)) {
            return self::NO_DATA_TEXT;
        }

        $trackers = $payload['trackers'] ?? null;
        if (!\is_array($trackers) || [] === $trackers) {
            return self::NO_DATA_TEXT;
        }

        $lines = [];
        $count = 0;
        foreach ($trackers as $tracker) {
            if ($count >= self::MAX_ROWS) {
                break;
            }
            $line = $this->renderTrackerLine($tracker);
            if (null === $line) {
                continue;
            }
            $lines[] = $line;
            ++$count;
        }

        if ([] === $lines) {
            return self::NO_DATA_TEXT;
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

        $trackers = $payload['trackers'] ?? null;
        if (!\is_array($trackers)) {
            return '';
        }

        $renderableCount = 0;
        foreach ($trackers as $tracker) {
            if (null !== $this->renderTrackerLine($tracker)) {
                ++$renderableCount;
            }
        }

        return $renderableCount.' '.(1 === $renderableCount ? 'tracker' : 'trackers');
    }

    private function renderTrackerLine(mixed $tracker): ?string
    {
        if (!\is_array($tracker)) {
            return null;
        }

        $label = $this->formatString($tracker['label'] ?? $tracker['name'] ?? null);
        $value = $this->formatScalar($tracker['value'] ?? null);

        if (null === $label && null === $value) {
            return null;
        }

        $labelText = $label ?? '-';
        $valueText = $value ?? '-';

        return $labelText.': '.$valueText;
    }

    private function formatString(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return TerminalSafeText::stripControlBytes($value);
    }

    private function formatScalar(mixed $value): ?string
    {
        if (\is_string($value)) {
            if ('' === $value) {
                return null;
            }

            return TerminalSafeText::stripControlBytes($value);
        }
        if (\is_int($value) || \is_float($value)) {
            return TerminalSafeText::stripControlBytes((string) $value);
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return null;
    }
}
