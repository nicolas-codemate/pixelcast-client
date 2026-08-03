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

    /**
     * The reason nothing could be read from the target, or null when it answered.
     */
    public function unreadableReason(): ?string;
}
