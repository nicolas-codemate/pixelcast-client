<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class TrackerState extends AbstractPushedPayloadState
{
    protected function payloadKey(): string
    {
        return 'trackers';
    }
}
