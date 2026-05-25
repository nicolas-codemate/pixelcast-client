<?php

declare(strict_types=1);

namespace App\Tests\Tui\Inspector;

use App\Tui\Inspector\InspectorHttpClient;
use PHPUnit\Framework\TestCase;

final class InspectorHttpClientTest extends TestCase
{
    public function testFetchReturnsUnreachableWhenBaseUrlIsNull(): void
    {
        $client = new InspectorHttpClient();

        $snapshot = $client->fetch(null);

        self::assertFalse($snapshot->reachable);
        self::assertSame('no base URL configured', $snapshot->errorMessage);
        self::assertNull($snapshot->state);
        self::assertNull($snapshot->requests);
    }

    public function testFetchReturnsUnreachableWhenBaseUrlIsEmptyString(): void
    {
        $client = new InspectorHttpClient();

        $snapshot = $client->fetch('');

        self::assertFalse($snapshot->reachable);
        self::assertSame('no base URL configured', $snapshot->errorMessage);
    }

    public function testFetchReturnsUnreachableWhenHostIsUnroutable(): void
    {
        $client = new InspectorHttpClient(timeoutSeconds: 0.1);

        $snapshot = $client->fetch('http://127.0.0.1:1/');

        self::assertFalse($snapshot->reachable);
        self::assertSame('connection failed', $snapshot->errorMessage);
        self::assertNull($snapshot->state);
        self::assertNull($snapshot->requests);
    }
}
