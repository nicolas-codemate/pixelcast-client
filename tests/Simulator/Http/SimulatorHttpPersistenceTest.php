<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Simulator\State\PersistedStateReader;
use Symfony\Component\HttpFoundation\Response;

final class SimulatorHttpPersistenceTest extends SimulatorHttpTestCase
{
    private const array WEATHER_PAYLOAD = [
        'current' => ['icon' => 'w_rain', 'temp' => 9],
        'forecast' => [],
    ];

    public function testStatePostedInOneProcessIsVisibleInAnother(): void
    {
        $postResponse = $this->server->post('/api/weather', self::WEATHER_PAYLOAD);
        self::assertSame(Response::HTTP_OK, $postResponse->statusCode, $this->explain($postResponse));

        $weatherState = self::domainState($this->inspect(), 'weather');

        self::assertTrue($weatherState['valid'] ?? null, $this->server->serverOutput());
        self::assertSame(['icon' => 'w_rain', 'temp' => 9], $weatherState['current'] ?? null);
    }

    public function testRequestLogPersistsAcrossProcesses(): void
    {
        $postResponse = $this->server->post('/api/weather', self::WEATHER_PAYLOAD);
        self::assertSame(Response::HTTP_OK, $postResponse->statusCode, $this->explain($postResponse));

        $loggedRequests = self::loggedRequests($this->inspect());
        self::assertCount(1, $loggedRequests, $this->server->serverOutput());

        $firstLoggedRequest = $loggedRequests[0] ?? [];
        self::assertSame('POST', $firstLoggedRequest['method'] ?? null);
        self::assertSame('/api/weather', $firstLoggedRequest['path'] ?? null);
        self::assertSame(['valid' => true], $firstLoggedRequest['validation'] ?? null);
    }

    public function testResetPurgesPersistedState(): void
    {
        $this->server->post('/api/weather', self::WEATHER_PAYLOAD);

        $resetResponse = $this->server->post('/api/__reset');
        self::assertSame(Response::HTTP_OK, $resetResponse->statusCode, $this->explain($resetResponse));

        // This request also proves the reset process finished writing its state file.
        $inspectPayload = $this->inspect();
        self::assertFalse(self::domainState($inspectPayload, 'weather')['valid'] ?? null, $this->server->serverOutput());
        self::assertSame([], $inspectPayload['requests'] ?? null);

        $persistedState = $this->server->decodedStateFile();
        $persistedDomains = PersistedStateReader::payloadMap($persistedState['states'] ?? null);

        self::assertNull($persistedDomains['weather']['current'] ?? null);
        self::assertSame([], $persistedState['requestLog'] ?? null);
    }

    public function testBarePathsAreNotServed(): void
    {
        $bareResponse = $this->server->post('/weather', self::WEATHER_PAYLOAD);
        self::assertSame(Response::HTTP_NOT_FOUND, $bareResponse->statusCode, $this->explain($bareResponse));

        $prefixedResponse = $this->server->post('/api/weather', self::WEATHER_PAYLOAD);
        self::assertSame(Response::HTTP_OK, $prefixedResponse->statusCode, $this->explain($prefixedResponse));
    }
}
