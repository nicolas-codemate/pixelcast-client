<?php

declare(strict_types=1);

namespace App\Scenario\Transport;

use App\Scenario\ScenarioResult;

interface ScenarioTransport
{
    /**
     * @param array<string,mixed>|null $body
     */
    public function send(string $method, string $url, ?array $body): ScenarioResult;
}
