<?php

declare(strict_types=1);

namespace App\Tui\DeviceState;

use App\Domain\AppDomain;

interface DeviceStateSource
{
    public function getDomainState(AppDomain $domain): DeviceDomainState;

    /**
     * @return array<string, DeviceDomainState> keyed by AppDomain::value
     */
    public function snapshot(): array;

    public function refresh(float $deltaSeconds): bool;
}
