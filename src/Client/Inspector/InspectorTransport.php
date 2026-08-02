<?php

declare(strict_types=1);

namespace App\Client\Inspector;

interface InspectorTransport
{
    public function fetch(?string $baseUrl): InspectorSnapshot;
}
