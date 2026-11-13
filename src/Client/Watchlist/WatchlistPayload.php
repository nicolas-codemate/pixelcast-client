<?php

declare(strict_types=1);

namespace App\Client\Watchlist;

use App\Client\StaleBehavior;

final readonly class WatchlistPayload
{
    public const int MAXIMUM_NAME_LENGTH = 13;
    public const int MAXIMUM_ROWS = 9;

    /**
     * @param list<WatchlistRow> $rows
     */
    private function __construct(
        public string $name,
        public array $rows,
        public ?int $displayDurationMilliseconds,
        public ?int $staleAfterInSeconds,
        public ?StaleBehavior $staleBehavior,
    ) {
    }

    /**
     * @param list<WatchlistRow> $rows
     */
    public static function create(
        string $name,
        array $rows,
        ?int $displayDurationMilliseconds = null,
        ?int $staleAfterInSeconds = null,
        ?StaleBehavior $staleBehavior = null,
    ): self {
        if ('' === $name) {
            throw new \InvalidArgumentException('A watchlist needs a non-empty name.');
        }

        if (mb_strlen($name) > self::MAXIMUM_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A watchlist name holds at most %d characters, got %d.', self::MAXIMUM_NAME_LENGTH, mb_strlen($name)));
        }

        if (\count($rows) > self::MAXIMUM_ROWS) {
            throw new \InvalidArgumentException(\sprintf('A watchlist carries at most %d rows, got %d.', self::MAXIMUM_ROWS, \count($rows)));
        }

        return new self(
            name: $name,
            rows: $rows,
            displayDurationMilliseconds: $displayDurationMilliseconds,
            staleAfterInSeconds: $staleAfterInSeconds,
            staleBehavior: $staleBehavior,
        );
    }

    /**
     * The name is absent on purpose: it travels as a query parameter, the only form DELETE /watchlist accepts.
     *
     * @return array{rows: list<array{symbol: string, icon?: string, value: float, currency?: string, change: float, symbolColor?: string}>, duration?: int, staleAfter?: int, staleBehavior?: string}
     */
    public function toArray(): array
    {
        // Always emitted: the firmware replaces the stored rows whole, so an empty list is the
        // instruction that clears them rather than an absent key that leaves them untouched.
        $payload = [
            'rows' => array_map(
                static fn (WatchlistRow $row): array => $row->toArray(),
                $this->rows,
            ),
        ];

        if (null !== $this->displayDurationMilliseconds) {
            $payload['duration'] = $this->displayDurationMilliseconds;
        }

        if (null !== $this->staleAfterInSeconds) {
            $payload['staleAfter'] = $this->staleAfterInSeconds;
        }

        if (null !== $this->staleBehavior) {
            $payload['staleBehavior'] = $this->staleBehavior->value;
        }

        return $payload;
    }
}
