<?php

declare(strict_types=1);

namespace App\Tui\DeviceStatus;

interface StatsTransport
{
    public function fetch(?string $baseUrl): StatsSnapshot;
}
