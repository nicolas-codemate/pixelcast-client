<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Client\PixelcastClient;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use App\Simulator\State\PersistedStateReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
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

    protected function buildPixelcastClient(): PixelcastClient
    {
        $deviceBaseUrl = $this->server->baseUrl.'/api';
        $validatorFactory = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 3), $deviceBaseUrl);

        return new PixelcastClient(
            HttpClient::createForBaseUri($deviceBaseUrl.'/'),
            new OutboundPayloadValidator($validatorFactory->create(), new Psr17Factory(), $deviceBaseUrl),
        );
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

    /**
     * @param array<string, mixed> $inspectPayload
     * @param array<string, mixed>|null $expectedBody
     */
    protected function assertLoggedRequest(array $inspectPayload, int $position, int $expectedCount, string $expectedMethod, string $expectedPath, ?array $expectedBody): void
    {
        $loggedRequests = self::loggedRequests($inspectPayload);
        self::assertCount($expectedCount, $loggedRequests, $this->server->serverOutput());

        $loggedRequest = $loggedRequests[$position] ?? [];
        self::assertSame($expectedMethod, $loggedRequest['method'] ?? null);
        self::assertSame($expectedPath, $loggedRequest['path'] ?? null);
        self::assertSame(['valid' => true], $loggedRequest['validation'] ?? null);
        self::assertSame($expectedBody, $loggedRequest['body'] ?? null);
    }

    /**
     * @param array<string, mixed> $inspectPayload
     *
     * @return array<string, mixed>
     */
    protected static function domainState(array $inspectPayload, string $domainKey): array
    {
        return PersistedStateReader::payloadMap($inspectPayload['state'] ?? null)[$domainKey] ?? [];
    }

    protected function explain(SimulatorHttpResponse $response): string
    {
        return $response->body."\n".$this->server->serverOutput();
    }
}
