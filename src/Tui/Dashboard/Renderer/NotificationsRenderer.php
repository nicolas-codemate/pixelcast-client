<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Renderer;

use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\TerminalSafeText;

final class NotificationsRenderer implements DomainRenderer
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

        $notifications = $this->extractNotifications($payload);
        if ([] === $notifications) {
            return self::NO_DATA_TEXT;
        }

        $lines = [];
        $count = 0;
        foreach ($notifications as $notification) {
            if ($count >= self::MAX_ROWS) {
                break;
            }
            $line = $this->renderNotificationLine($notification);
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

    /**
     * @param array<mixed> $payload
     *
     * @return list<mixed>
     */
    private function extractNotifications(array $payload): array
    {
        $queue = $payload['queue'] ?? $payload['notifications'] ?? null;
        if (!\is_array($queue)) {
            return [];
        }

        return array_values($queue);
    }

    private function renderNotificationLine(mixed $notification): ?string
    {
        if (!\is_array($notification)) {
            return null;
        }

        $priority = $this->formatString($notification['priority'] ?? null);
        $text = $this->formatString($notification['text'] ?? $notification['message'] ?? null);

        if (null === $text) {
            return null;
        }

        if (null === $priority) {
            return $text;
        }

        return '['.$priority.'] '.$text;
    }

    private function formatString(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return TerminalSafeText::stripControlBytes((string) $value);
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return TerminalSafeText::stripControlBytes($value);
    }
}
