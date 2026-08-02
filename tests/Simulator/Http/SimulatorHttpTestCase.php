<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Simulator\State\PersistedStateReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class SimulatorHttpTestCase extends TestCase
{
    protected SimulatorHttpServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = SimulatorHttpServer::start(\dirname(__DIR__, 3));
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspect(): array
    {
        $inspectResponse = $this->server->get('/api/__inspect');
        self::assertSame(Response::HTTP_OK, $inspectResponse->statusCode, $this->explain($inspectResponse));

        return $inspectResponse->decodedBody();
    }

    /**
     * @param array<string, mixed> $inspectPayload
     *
     * @return list<array<string, mixed>>
     */
    protected static function loggedRequests(array $inspectPayload): array
    {
        return PersistedStateReader::payloadList($inspectPayload['requests'] ?? null);
    }

    protected function explain(SimulatorHttpResponse $response): string
    {
        return $response->body."\n".$this->server->serverOutput();
    }
}
