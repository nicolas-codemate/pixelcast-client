<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Simulator\Controller\WatchlistController;
use Symfony\Component\HttpFoundation\Response;

final class WatchlistControllerTest extends SimulatorWebTestCase
{
    public function testPostThenListIncludesTheWatchlist(): void
    {
        $this->postJson('/api/watchlist?name=stocks', self::watchlistPushPayload());
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $first = $this->firstItemOfList('/api/watchlists', 'watchlists');

        self::assertSame(1, $this->jsonResponse()['count'] ?? null);
        self::assertSame('stocks', $first['name'] ?? null);
        self::assertSame(1, $first['rowCount'] ?? null);
    }

    public function testARowCarriesTheDeviceEchoForWhatThePushOmitted(): void
    {
        $this->postJson('/api/watchlist?name=stocks', ['rows' => [['symbol' => 'Meta']]]);

        self::assertSame(
            [
                'symbol' => 'Meta',
                'icon' => '',
                'currency' => '',
                'value' => 0,
                'change' => 0,
                'symbolColor' => WatchlistController::DEFAULT_ROW_SYMBOL_COLOR,
            ],
            $this->firstItemOfList('/api/watchlist?name=stocks', 'rows'),
        );
    }

    public function testASymbolColorPushedAsAnRgbArrayIsReadBackAsAHexString(): void
    {
        $this->postJson('/api/watchlist?name=stocks', [
            'rows' => [['symbol' => 'Meta', 'symbolColor' => [255, 136, 0]]],
        ]);

        self::assertSame('#FF8800', $this->firstItemOfList('/api/watchlist?name=stocks', 'rows')['symbolColor'] ?? null);
    }

    public function testAPushWithoutRowsKeepsTheStoredRows(): void
    {
        $this->postJson('/api/watchlist?name=stocks', self::watchlistPushPayload());
        $this->postJson('/api/watchlist?name=stocks', ['staleAfter' => 7200]);

        $this->client->request('GET', '/api/watchlist?name=stocks');
        $payload = $this->jsonResponse();

        self::assertSame('SpaceX', $this->firstItemOfList('/api/watchlist?name=stocks', 'rows')['symbol'] ?? null);
        self::assertSame(7200, $payload['staleAfter'] ?? null);
    }

    public function testGetExistingAnnouncesTheContractFreshnessDefaults(): void
    {
        $this->postJson('/api/watchlist?name=stocks', self::watchlistPushPayload());

        $this->client->request('GET', '/api/watchlist?name=stocks');
        $payload = $this->jsonResponse();

        self::assertSame(0, $payload['age'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
        self::assertSame(WatchlistController::DEFAULT_STALE_AFTER_SECONDS, $payload['staleAfter'] ?? null);
        self::assertSame(WatchlistController::DEFAULT_STALE_BEHAVIOR, $payload['staleBehavior'] ?? null);
    }

    public function testGetUnknownReturns404(): void
    {
        $this->client->request('GET', '/api/watchlist?name=unknown');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesTheWatchlistAndAnUnknownNameIs404(): void
    {
        $this->postJson('/api/watchlist?name=stocks', self::watchlistPushPayload());

        $this->client->request('DELETE', '/api/watchlist?name=stocks');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/watchlist?name=stocks');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testAPostWithoutNameIsRejected(): void
    {
        $this->postJson('/api/watchlist', self::watchlistPushPayload());

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }
}
