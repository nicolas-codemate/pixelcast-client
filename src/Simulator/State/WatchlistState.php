<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class WatchlistState extends AbstractPushedPayloadState
{
    protected function payloadKey(): string
    {
        return 'watchlists';
    }
}
