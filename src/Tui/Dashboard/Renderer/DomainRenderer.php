<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Renderer;

use App\Tui\DeviceState\DeviceDomainState;

interface DomainRenderer
{
    public function render(DeviceDomainState $state): string;
}
