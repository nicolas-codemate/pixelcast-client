<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Claude\ClaudeAuthorizationRequest;
use App\Claude\ClaudeAuthorizationRequestFactory;

final class StaticClaudeAuthorizationRequestFactoryStub implements ClaudeAuthorizationRequestFactory
{
    public function __construct(
        private readonly ClaudeAuthorizationRequest $authorizationRequest,
    ) {
    }

    public function create(): ClaudeAuthorizationRequest
    {
        return $this->authorizationRequest;
    }
}
