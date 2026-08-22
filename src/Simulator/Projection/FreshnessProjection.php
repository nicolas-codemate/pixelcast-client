<?php

declare(strict_types=1);

namespace App\Simulator\Projection;

final class FreshnessProjection
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array{age: int, stale: bool, staleAfter: int, staleBehavior: string}
     */
    public static function of(
        array $payload,
        ?\DateTimeImmutable $pushedAt,
        \DateTimeImmutable $now,
        int $defaultStaleAfterSeconds,
        string $defaultStaleBehavior,
    ): array {
        $pushedStaleAfter = $payload['staleAfter'] ?? null;
        $staleAfter = \is_int($pushedStaleAfter) ? $pushedStaleAfter : $defaultStaleAfterSeconds;

        $pushedStaleBehavior = $payload['staleBehavior'] ?? null;
        $staleBehavior = \is_string($pushedStaleBehavior) ? $pushedStaleBehavior : $defaultStaleBehavior;

        $age = null === $pushedAt ? 0 : max(0, $now->getTimestamp() - $pushedAt->getTimestamp());

        // A staleAfter of 0 means the app never goes stale.
        $stale = 0 !== $staleAfter && $age > $staleAfter;

        return [
            'age' => $age,
            'stale' => $stale,
            'staleAfter' => $staleAfter,
            'staleBehavior' => $staleBehavior,
        ];
    }
}
