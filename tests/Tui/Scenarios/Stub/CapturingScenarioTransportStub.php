<?php

declare(strict_types=1);

namespace App\Tests\Tui\Scenarios\Stub;

use App\Tui\Scenarios\ScenarioResult;
use App\Tui\Scenarios\Transport\ScenarioTransport;

final class CapturingScenarioTransportStub implements ScenarioTransport
{
    /**
     * @var list<array{method: string, url: string, body: array<string,mixed>|null}>
     */
    public array $calls = [];

    public function __construct(
        private readonly ScenarioResult $resultToReturn,
    ) {
    }

    public function send(string $method, string $url, ?array $body): ScenarioResult
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
        ];

        return $this->resultToReturn;
    }
}
