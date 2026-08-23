<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class CustomAppState extends AbstractPushedPayloadState
{
    protected function payloadKey(): string
    {
        return 'apps';
    }

    public function domainKey(): string
    {
        return 'customApps';
    }
}
