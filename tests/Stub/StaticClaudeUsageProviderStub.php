<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\Gauge\GaugePayload;
use App\Provider\Claude\ClaudeUsageProviderInterface;

final class StaticClaudeUsageProviderStub implements ClaudeUsageProviderInterface
{
    public function __construct(
        private readonly ?GaugePayload $gauge = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function fetchUsageGauge(): ?GaugePayload
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->gauge;
    }
}
