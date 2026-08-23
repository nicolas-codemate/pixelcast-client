<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Simulator\Controller\TrackerController;
use Symfony\Component\HttpFoundation\Response;

final class TrackerControllerTest extends SimulatorWebTestCase
{
    public function testPostThenListIncludesTracker(): void
    {
        $this->postJson('/api/tracker?name=BTC', self::trackerPushPayload());
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/trackers');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        $trackers = $payload['trackers'] ?? null;
        self::assertIsArray($trackers);
        self::assertNotEmpty($trackers);

        $first = $trackers[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('BTC', $first['name'] ?? null);
        self::assertSame('BTC', $first['symbol'] ?? null);
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/api/tracker?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('not found', $payload['error'] ?? null);
    }

    public function testGetUnknownReturns404(): void
    {
        $this->client->request('GET', '/api/tracker?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testGetExistingReturnsPayload(): void
    {
        $this->postJson('/api/tracker?name=BTC', self::trackerPushPayload());

        $this->client->request('GET', '/api/tracker?name=BTC');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('BTC', $payload['name'] ?? null);
        self::assertSame('BTC', $payload['symbol'] ?? null);
    }

    public function testGetExistingAnnouncesTheContractFreshnessDefaults(): void
    {
        $this->postJson('/api/tracker?name=BTC', self::trackerPushPayload());

        $this->client->request('GET', '/api/tracker?name=BTC');
        $payload = $this->jsonResponse();

        self::assertSame(0, $payload['age'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
        self::assertSame(TrackerController::DEFAULT_STALE_AFTER_SECONDS, $payload['staleAfter'] ?? null);
        self::assertSame(TrackerController::DEFAULT_STALE_BEHAVIOR, $payload['staleBehavior'] ?? null);
    }

    public function testThePushedFreshnessPolicyIsReadBackFromTheSingleTracker(): void
    {
        $this->postJson('/api/tracker?name=BTC', [
            ...self::trackerPushPayload(),
            'staleAfter' => 7200,
            'staleBehavior' => 'badge',
        ]);

        $this->client->request('GET', '/api/tracker?name=BTC');
        $payload = $this->jsonResponse();

        self::assertSame(7200, $payload['staleAfter'] ?? null);
        self::assertSame('badge', $payload['staleBehavior'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
    }

    public function testAStaleAfterOfZeroIsReadBackAsNeverGoingStale(): void
    {
        $this->postJson('/api/tracker?name=BTC', [...self::trackerPushPayload(), 'staleAfter' => 0]);

        $this->client->request('GET', '/api/tracker?name=BTC');
        $payload = $this->jsonResponse();

        self::assertSame(0, $payload['staleAfter'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
    }
}
