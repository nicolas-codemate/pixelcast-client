<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\Inspector\InspectorSnapshot;
use App\Client\Inspector\InspectorTransport;

final class RecordingInspectorTransportStub implements InspectorTransport
{
    public int $fetchCount = 0;

    public function __construct(private readonly InspectorSnapshot $cannedSnapshot)
    {
    }

    public function fetch(?string $baseUrl): InspectorSnapshot
    {
        ++$this->fetchCount;

        return $this->cannedSnapshot;
    }
}
