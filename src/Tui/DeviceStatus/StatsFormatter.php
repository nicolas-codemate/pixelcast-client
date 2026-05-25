<?php

declare(strict_types=1);

namespace App\Tui\DeviceStatus;

final class StatsFormatter
{
    private const string MISSING_VALUE = 'n/a';

    public static function format(StatsSnapshot $snapshot): string
    {
        $lines = [
            'Uptime:    '.self::formatUptime($snapshot->uptimeSeconds),
            'Free heap: '.self::formatBytes($snapshot->freeHeapBytes),
            'IP:        '.self::formatString($snapshot->ipAddress),
            'Firmware:  '.self::formatString($snapshot->firmwareVersion),
            '',
            '[Settings]',
            'Brightness:       '.self::formatInt($snapshot->brightness),
            'Auto rotate:      '.self::formatBool($snapshot->autoRotate),
            'Default duration: '.self::formatDuration($snapshot->defaultDurationSeconds),
        ];

        return implode("\n", $lines);
    }

    public static function formatUptime(?int $seconds): string
    {
        if (null === $seconds || $seconds < 0) {
            return self::MISSING_VALUE;
        }

        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);
        $secondsRemainder = $seconds % 60;

        if ($days > 0) {
            return \sprintf('%dd %02dh %02dm %02ds', $days, $hours, $minutes, $secondsRemainder);
        }

        if ($hours > 0) {
            return \sprintf('%dh %02dm %02ds', $hours, $minutes, $secondsRemainder);
        }

        if ($minutes > 0) {
            return \sprintf('%dm %02ds', $minutes, $secondsRemainder);
        }

        return \sprintf('%ds', $secondsRemainder);
    }

    public static function formatBytes(?int $bytes): string
    {
        if (null === $bytes || $bytes < 0) {
            return self::MISSING_VALUE;
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return \sprintf('%.1f KB', $bytes / 1024);
    }

    public static function formatBool(?bool $value): string
    {
        return match ($value) {
            true => 'enabled',
            false => 'disabled',
            null => self::MISSING_VALUE,
        };
    }

    private static function formatString(?string $value): string
    {
        return (null === $value || '' === $value) ? self::MISSING_VALUE : $value;
    }

    private static function formatInt(?int $value): string
    {
        return null === $value ? self::MISSING_VALUE : (string) $value;
    }

    private static function formatDuration(?int $seconds): string
    {
        return null === $seconds ? self::MISSING_VALUE : $seconds.'s';
    }
}
