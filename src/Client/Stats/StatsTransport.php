<?php

declare(strict_types=1);

namespace App\Client\Stats;

interface StatsTransport
{
    public function fetch(?string $baseUrl): StatsSnapshot;
}
