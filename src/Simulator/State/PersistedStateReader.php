<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class PersistedStateReader
{
    /**
     * @return array<string, mixed>|null
     */
    public static function payload(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $stringKeyed = [];
        foreach ($value as $key => $item) {
            $stringKeyed[(string) $key] = $item;
        }

        return $stringKeyed;
    }

    public static function atomDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!'.\DateTimeInterface::ATOM, $value);

        return false === $parsed ? null : $parsed;
    }

    /**
     * @return array<string, \DateTimeImmutable>
     */
    public static function atomDateMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $datesByName = [];
        foreach ($value as $name => $item) {
            $date = self::atomDate($item);
            if (null !== $date) {
                $datesByName[(string) $name] = $date;
            }
        }

        return $datesByName;
    }

    /**
     * @param array<string, \DateTimeImmutable> $datesByName
     *
     * @return array<string, string>
     */
    public static function atomStringMap(array $datesByName): array
    {
        return array_map(
            static fn (\DateTimeImmutable $date): string => $date->format(\DateTimeInterface::ATOM),
            $datesByName,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function payloadList(mixed $value): array
    {
        return array_values(self::payloadMap($value));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function payloadMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $payloadsByName = [];
        foreach ($value as $name => $item) {
            $payload = self::payload($item);
            if (null !== $payload) {
                $payloadsByName[(string) $name] = $payload;
            }
        }

        return $payloadsByName;
    }
}
