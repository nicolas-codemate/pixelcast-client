<?php

declare(strict_types=1);

namespace App\Tui\Inspector;

use App\Domain\AppDomain;

final class StateFormatter
{
    /**
     * @param array<string, mixed>|null $state
     */
    public static function format(?array $state): string
    {
        if (null === $state || [] === $state) {
            return 'No data';
        }

        $appDomainKeys = array_map(static fn (AppDomain $domain) => $domain->value, AppDomain::cases());
        $orderedKeys = [...$appDomainKeys, 'brightness', 'settings'];

        $lines = [];

        foreach ($orderedKeys as $domain) {
            $lines[] = \sprintf('[%s]', $domain);
            if (!\array_key_exists($domain, $state)) {
                $lines[] = '  (empty)';
                continue;
            }
            self::appendBodyLines($lines, $state[$domain]);
        }

        $extraDomains = array_diff(array_keys($state), $orderedKeys);
        sort($extraDomains);
        foreach ($extraDomains as $domain) {
            $lines[] = \sprintf('[%s]', $domain);
            self::appendBodyLines($lines, $state[$domain]);
        }

        while ([] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private static function appendBodyLines(array &$lines, mixed $value): void
    {
        if (!\is_array($value)) {
            $lines[] = '  '.self::renderScalar($value);

            return;
        }

        if ([] === $value) {
            $lines[] = '  (empty)';

            return;
        }

        foreach ($value as $key => $itemValue) {
            $lines[] = \sprintf('  %s: %s', (string) $key, self::renderValue($itemValue));
        }
    }

    private static function renderValue(mixed $value): string
    {
        if (\is_array($value)) {
            return (string) json_encode($value, \JSON_UNESCAPED_SLASHES);
        }

        return self::renderScalar($value);
    }

    private static function renderScalar(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (true === $value) {
            return 'true';
        }
        if (false === $value) {
            return 'false';
        }
        if (null === $value) {
            return 'null';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES);
    }
}
