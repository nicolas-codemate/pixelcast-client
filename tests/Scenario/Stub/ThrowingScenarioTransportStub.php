<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Stub;

use App\Scenario\ScenarioResult;
use App\Scenario\Transport\ScenarioTransport;

final class ThrowingScenarioTransportStub implements ScenarioTransport
{
    public function __construct(
        private readonly \Throwable $exceptionToThrow,
    ) {
    }

    public function send(string $method, string $url, ?array $body): ScenarioResult
    {
        throw $this->exceptionToThrow;
    }
}
