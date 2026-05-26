<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Renderer;

use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\TerminalSafeText;

final class IconsRenderer implements DomainRenderer
{
    private const string NO_DATA_TEXT = 'no data';
    private const int MAX_NAMES = 5;

    public function render(DeviceDomainState $state): string
    {
        if (false === $state->hasData) {
            return self::NO_DATA_TEXT;
        }

        $payload = $state->payload;
        if (!\is_array($payload)) {
            return self::NO_DATA_TEXT;
        }

        $icons = $payload['icons'] ?? null;
        if (!\is_array($icons) || [] === $icons) {
            return self::NO_DATA_TEXT;
        }

        $names = [];
        foreach ($icons as $icon) {
            $name = $this->extractIconName($icon);
            if (null === $name) {
                continue;
            }
            $names[] = $name;
        }

        $totalCount = \count($icons);
        $previewNames = \array_slice($names, 0, self::MAX_NAMES);

        $lines = ['Count: '.$totalCount];
        foreach ($previewNames as $previewName) {
            $lines[] = '- '.$previewName;
        }

        return implode("\n", $lines);
    }

    private function extractIconName(mixed $icon): ?string
    {
        if (\is_string($icon)) {
            return '' === $icon ? null : TerminalSafeText::stripControlBytes($icon);
        }
        if (\is_array($icon)) {
            $name = $icon['name'] ?? null;
            if (\is_string($name) && '' !== $name) {
                return TerminalSafeText::stripControlBytes($name);
            }
        }

        return null;
    }
}
