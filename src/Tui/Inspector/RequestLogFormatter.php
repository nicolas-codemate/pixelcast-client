<?php

declare(strict_types=1);

namespace App\Tui\Inspector;

final class RequestLogFormatter
{
    private const ERROR_MAX_LENGTH = 60;

    /**
     * @param list<array<string, mixed>>|null $requests
     */
    public static function format(?array $requests): string
    {
        if (null === $requests || [] === $requests) {
            return 'No data';
        }

        $newestFirst = array_reverse($requests);

        $lines = [];
        foreach ($newestFirst as $entry) {
            $lines[] = self::renderEntry($entry);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function renderEntry(array $entry): string
    {
        $time = self::renderTime($entry['timestamp'] ?? null);
        $method = isset($entry['method']) && \is_string($entry['method']) ? $entry['method'] : '?';
        $path = isset($entry['path']) && \is_string($entry['path']) ? $entry['path'] : '?';

        $rawValidation = $entry['validation'] ?? null;
        /** @var array<string, mixed> $validation */
        $validation = \is_array($rawValidation)
            ? $rawValidation
            : ['valid' => false, 'error' => 'missing'];

        $verdict = self::renderVerdict($validation);

        return \sprintf('%s  %s  %s  %s', $time, $method, $path, $verdict);
    }

    private static function renderTime(mixed $timestamp): string
    {
        if (!\is_string($timestamp)) {
            return '??:??:??';
        }

        try {
            $parsed = new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return '??:??:??';
        }

        return $parsed->format('H:i:s');
    }

    /**
     * @param array<string, mixed> $validation
     */
    private static function renderVerdict(array $validation): string
    {
        $isValid = isset($validation['valid']) && true === $validation['valid'];
        if ($isValid) {
            return 'OK';
        }

        $errorRaw = $validation['error'] ?? 'unknown';
        $errorMessage = \is_string($errorRaw) ? $errorRaw : 'unknown';
        if (\strlen($errorMessage) > self::ERROR_MAX_LENGTH) {
            $errorMessage = substr($errorMessage, 0, self::ERROR_MAX_LENGTH);
        }

        return 'FAIL: '.$errorMessage;
    }
}
