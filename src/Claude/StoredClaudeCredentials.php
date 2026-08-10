<?php

declare(strict_types=1);

namespace App\Claude;

/**
 * The two slots the credentials file holds: the pair in use, and the pair it replaced.
 */
final readonly class StoredClaudeCredentials
{
    public function __construct(
        public ClaudeCredentials $current,
        public ?ClaudeCredentials $previous = null,
    ) {
    }

    /**
     * Exactly two slots and never three: the pair that was current becomes the previous one, and
     * the pair it had itself replaced is dropped.
     */
    public function rotatedTo(ClaudeCredentials $newCredentials): self
    {
        return new self($newCredentials, $this->current);
    }
}
