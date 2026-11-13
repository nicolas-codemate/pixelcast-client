<?php

declare(strict_types=1);

namespace App\Tests\Provider\Watchlist;

use App\Client\StaleBehavior;
use App\Provider\Watchlist\BoursoramaWatchlistQuoteReader;
use App\Provider\Watchlist\WatchlistProvider;
use App\Tests\Factory\BoursoramaHttpClientFactory;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tracker\Boursorama\BoursoramaEndOfDayRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WatchlistProviderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string TRACKER_FIXTURES_DIR = __DIR__.'/../Tracker/Fixtures';
    private const string CONFIG_FILE = 'pixelcast-watchlist.yaml';

    public function testEveryQuoteBecomesARowInTheOrderOfTheConfiguration(): void
    {
        $watchlist = self::buildProvider(self::servingBothQuotes())->fetchWatchlist();

        self::assertNotNull($watchlist);
        self::assertSame('watchlist', $watchlist->name);
        self::assertSame(
            [
                ['symbol' => 'PEA Monde', 'icon' => 'etf', 'value' => 6.175, 'currency' => 'EUR', 'change' => -5.0, 'symbolColor' => '#FFFFFF'],
                ['symbol' => 'CW8', 'value' => 495.0, 'currency' => 'EUR', 'change' => -1.0],
            ],
            $watchlist->toArray()['rows'],
        );
    }

    public function testTheGroupFreshnessTravelsWithThePayload(): void
    {
        $watchlist = self::buildProvider(self::servingBothQuotes())->fetchWatchlist();

        self::assertNotNull($watchlist);
        self::assertSame(2700, $watchlist->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Dim, $watchlist->staleBehavior);
    }

    public function testOneUnreadableQuoteLeavesTheDeviceWithTheListItShows(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = BoursoramaHttpClientFactory::serving(
            [self::fixtureResponse('boursorama-ticks-single-bar.json')],
            [self::fixtureResponse('boursorama-session-dcam.json')],
        );

        self::assertNull(self::buildProvider($httpClient, $logger)->fetchWatchlist());

        $lastRecord = end($logger->records);
        self::assertIsArray($lastRecord);
        self::assertSame(LogLevel::WARNING, $lastRecord['level'] ?? null);
        self::assertSame('1rTCW8', $lastRecord['context']['symbol'] ?? null);
    }

    /**
     * The first asset has a running session, the second only its daily bars.
     */
    private static function servingBothQuotes(): MockHttpClient
    {
        return BoursoramaHttpClientFactory::serving(
            [self::fixtureResponse('boursorama-ticks-cw8.json')],
            [self::fixtureResponse('boursorama-session-dcam.json')],
        );
    }

    private static function buildProvider(MockHttpClient $httpClient, RecordingLoggerStub $logger = new RecordingLoggerStub()): WatchlistProvider
    {
        return new WatchlistProvider(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.self::CONFIG_FILE),
            new BoursoramaWatchlistQuoteReader(new BoursoramaEndOfDayRequest($httpClient), $logger),
            $logger,
        );
    }

    private static function fixtureResponse(string $fixtureFileName): MockResponse
    {
        return MockResponse::fromFile(self::TRACKER_FIXTURES_DIR.'/'.$fixtureFileName);
    }
}
