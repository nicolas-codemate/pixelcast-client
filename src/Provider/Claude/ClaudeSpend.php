<?php

declare(strict_types=1);

namespace App\Provider\Claude;

/**
 * The extra-usage credit balance: what has been spent against what the account allows.
 */
final readonly class ClaudeSpend
{
    private const int PERCENT_SCALE = 100;

    private function __construct(
        public ClaudeMoney $used,
        public ClaudeMoney $limit,
    ) {
    }

    public static function create(ClaudeMoney $used, ClaudeMoney $limit): self
    {
        return new self($used, $limit);
    }

    /**
     * Computed from the two amounts rather than read from the response's own `percent`, so that the
     * bar and the money on the same row can never tell two different stories.
     *
     * A limit of zero has no meaningful fill: the balance is disabled or unbounded, not empty.
     */
    public function percent(): ?int
    {
        $limitAmount = $this->limit->amount();
        if ($limitAmount <= 0.0) {
            return null;
        }

        return (int) round($this->used->amount() / $limitAmount * self::PERCENT_SCALE);
    }
}
