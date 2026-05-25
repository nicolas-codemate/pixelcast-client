<?php

declare(strict_types=1);

namespace App\Tui\Inspector;

interface InspectorTransport
{
    public function fetch(?string $baseUrl): InspectorSnapshot;
}
