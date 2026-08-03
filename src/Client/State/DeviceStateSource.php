<?php

declare(strict_types=1);

namespace App\Client\State;

use App\Domain\AppDomain;

interface DeviceStateSource
{
    public function getDomainState(AppDomain $domain): DeviceDomainState;

    /**
     * @return array<string, DeviceDomainState> keyed by AppDomain::value
     */
    public function snapshot(): array;

    public function reachabilityError(): ?string;
}
