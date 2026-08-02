<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Transport;

use App\Scenario\Transport\ScenarioHttpClient;
use PHPUnit\Framework\TestCase;

final class ScenarioHttpClientTest extends TestCase
{
    public function testSendReturnsUnreachableWhenHostIsUnroutable(): void
    {
        $client = new ScenarioHttpClient();

        $result = $client->send('POST', 'http://127.0.0.1:1/__reset', null);

        self::assertFalse($result->success);
        self::assertNull($result->httpStatus);
        self::assertNotSame('', $result->message);
    }
}
