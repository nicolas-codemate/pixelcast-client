<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Renderer;

use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\TerminalSafeText;

final class CustomAppsRenderer implements DomainRenderer
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

        $apps = $payload['apps'] ?? null;
        if (!\is_array($apps) || [] === $apps) {
            return self::NO_DATA_TEXT;
        }

        $names = [];
        foreach ($apps as $app) {
            $name = $this->extractAppName($app);
            if (null === $name) {
                continue;
            }
            $names[] = $name;
        }

        $totalCount = \count($apps);
        $previewNames = \array_slice($names, 0, self::MAX_NAMES);

        $lines = ['Count: '.$totalCount];
        foreach ($previewNames as $previewName) {
            $lines[] = '- '.$previewName;
        }

        return implode("\n", $lines);
    }

    private function extractAppName(mixed $app): ?string
    {
        if (\is_string($app)) {
            return '' === $app ? null : TerminalSafeText::stripControlBytes($app);
        }
        if (\is_array($app)) {
            $name = $app['name'] ?? null;
            if (\is_string($name) && '' !== $name) {
                return TerminalSafeText::stripControlBytes($name);
            }
        }

        return null;
    }
}
