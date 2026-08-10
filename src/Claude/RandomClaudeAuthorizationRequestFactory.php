<?php

declare(strict_types=1);

namespace App\Claude;

final readonly class RandomClaudeAuthorizationRequestFactory implements ClaudeAuthorizationRequestFactory
{
    public function create(): ClaudeAuthorizationRequest
    {
        return ClaudeAuthorizationRequest::createRandom();
    }
}
