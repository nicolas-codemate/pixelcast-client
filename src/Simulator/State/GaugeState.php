<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class GaugeState extends AbstractPushedPayloadState
{
    protected function payloadKey(): string
    {
        return 'gauges';
    }
}
