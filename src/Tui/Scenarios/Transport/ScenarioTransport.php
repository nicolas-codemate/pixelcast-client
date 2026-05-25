<?php

declare(strict_types=1);

namespace App\Tui\Scenarios\Transport;

use App\Tui\Scenarios\ScenarioResult;

interface ScenarioTransport
{
    /**
     * @param array<string,mixed>|null $body
     */
    public function send(string $method, string $url, ?array $body): ScenarioResult;
}
