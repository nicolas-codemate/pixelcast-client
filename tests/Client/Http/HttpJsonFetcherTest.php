<?php

declare(strict_types=1);

namespace App\Tests\Client\Http;

use App\Client\Http\HttpJsonFetcher;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpJsonFetcherTest extends TestCase
{
    private const string URL = 'http://device.test/api/weather';

    public function testJsonObjectIsDecodedIntoAnArray(): void
    {
        $fetcher = $this->buildFetcher(new MockResponse('{"current":{"temp":20}}'));

        self::assertSame(['current' => ['temp' => 20]], $fetcher->fetchJson(self::URL));
    }

    public function testNonJsonBodyReturnsNull(): void
    {
        $fetcher = $this->buildFetcher(new MockResponse('not json at all'));

        self::assertNull($fetcher->fetchJson(self::URL));
    }

    public function testNotFoundStatusReturnsNullAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $fetcher = $this->buildFetcher(new MockResponse('{"error":"unknown"}', ['http_code' => 404]), $logger);

        self::assertNull($fetcher->fetchJson(self::URL));
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
    }

    public function testTransportErrorReturnsNullAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $fetcher = $this->buildFetcher(new MockResponse('', ['error' => 'connection refused']), $logger);

        self::assertNull($fetcher->fetchJson(self::URL));
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(self::URL, $logger->records[0]['context']['url'] ?? null);
    }

    private function buildFetcher(MockResponse $response, ?LoggerInterface $logger = null): HttpJsonFetcher
    {
        return new HttpJsonFetcher(new MockHttpClient($response), $logger ?? new NullLogger());
    }
}
