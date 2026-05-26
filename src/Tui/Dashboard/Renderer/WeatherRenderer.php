<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Renderer;

use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\TerminalSafeText;

final class WeatherRenderer implements DomainRenderer
{
    private const string NO_DATA_TEXT = 'no data';
    private const int MAX_FORECAST_LINES = 3;

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

        $currentLine = $this->renderCurrentLine($payload['current'] ?? null);
        if (null !== $currentLine) {
            $lines[] = $currentLine;
        }

        foreach ($this->renderForecastLines($payload['forecast'] ?? null) as $forecastLine) {
            $lines[] = $forecastLine;
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

        $current = $payload['current'] ?? null;
        if (!\is_array($current)) {
            return '';
        }

        $temperature = $this->formatTemperature($current['tempC'] ?? null);
        $condition = $this->formatString($current['condition'] ?? null);

        if (null === $temperature && null === $condition) {
            return '';
        }
        if (null === $condition) {
            return $temperature;
        }
        if (null === $temperature) {
            return $condition;
        }

        return $temperature.' '.$condition;
    }

    private function renderCurrentLine(mixed $current): ?string
    {
        if (!\is_array($current)) {
            return null;
        }

        $temperature = $this->formatTemperature($current['tempC'] ?? null);
        $condition = $this->formatString($current['condition'] ?? null);

        if (null === $temperature && null === $condition) {
            return null;
        }

        $parts = ['Now:'];
        if (null !== $temperature) {
            $parts[] = $temperature;
        }
        if (null !== $condition) {
            $parts[] = $condition;
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function renderForecastLines(mixed $forecast): array
    {
        if (!\is_array($forecast)) {
            return [];
        }

        $lines = [];
        $count = 0;
        foreach ($forecast as $entry) {
            if ($count >= self::MAX_FORECAST_LINES) {
                break;
            }
            $line = $this->renderForecastEntry($entry);
            if (null === $line) {
                continue;
            }
            $lines[] = $line;
            ++$count;
        }

        return $lines;
    }

    private function renderForecastEntry(mixed $entry): ?string
    {
        if (!\is_array($entry)) {
            return null;
        }

        $day = $this->formatString($entry['day'] ?? $entry['date'] ?? null);
        $minTemperature = $this->formatTemperature($entry['minC'] ?? null);
        $maxTemperature = $this->formatTemperature($entry['maxC'] ?? null);
        $condition = $this->formatString($entry['condition'] ?? null);

        $parts = [];
        if (null !== $day) {
            $parts[] = $day.':';
        }
        if (null !== $minTemperature && null !== $maxTemperature) {
            $parts[] = $minTemperature.'/'.$maxTemperature;
        } elseif (null !== $maxTemperature) {
            $parts[] = $maxTemperature;
        }
        if (null !== $condition) {
            $parts[] = $condition;
        }

        if ([] === $parts) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function formatTemperature(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return TerminalSafeText::stripControlBytes((string) $value.'C');
        }
        if (\is_string($value) && '' !== $value) {
            return TerminalSafeText::stripControlBytes($value.'C');
        }

        return null;
    }

    private function formatString(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return TerminalSafeText::stripControlBytes($value);
    }
}
