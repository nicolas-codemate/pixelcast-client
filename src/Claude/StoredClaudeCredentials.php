<?php

declare(strict_types=1);

namespace App\Claude;

/**
 * The two slots the credentials file holds: the pair in use, and the pair it replaced.
 *
 * `unusableSince` records that both slots were refused by the server, which no amount of retrying
 * undoes — only a fresh login does. Without it the poller would spend two token exchanges every
 * cycle, for as long as nobody notices, and those exchanges are what rate-limits the endpoint the
 * operator has to reach to repair it.
 */
final readonly class StoredClaudeCredentials
{
    public function __construct(
        public ClaudeCredentials $current,
        public ?ClaudeCredentials $previous = null,
        public ?\DateTimeImmutable $unusableSince = null,
    ) {
    }

    /**
     * Exactly two slots and never three: the pair that was current becomes the previous one, and
     * the pair it had itself replaced is dropped. A rotation that succeeded clears the mark.
     */
    public function rotatedTo(ClaudeCredentials $newCredentials): self
    {
        return new self($newCredentials, $this->current);
    }

    public function markedUnusableAt(\DateTimeImmutable $instant): self
    {
        return new self($this->current, $this->previous, $instant);
    }

    public function isUnusable(): bool
    {
        return null !== $this->unusableSince;
    }
}
