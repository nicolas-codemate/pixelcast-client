<?php

declare(strict_types=1);

namespace App\Claude;

/**
 * Hands out the PKCE parameters of one login attempt. It exists as a seam for the same reason the
 * clock does: what it produces is unpredictable by design, and a test has to know it in advance.
 */
interface ClaudeAuthorizationRequestFactory
{
    public function create(): ClaudeAuthorizationRequest;
}
