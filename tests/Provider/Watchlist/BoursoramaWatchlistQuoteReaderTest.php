<?php

declare(strict_types=1);

namespace App\Tests\Provider\Watchlist;

use App\Provider\Watchlist\BoursoramaWatchlistQuoteReader;
use App\Tests\Factory\BoursoramaHttpClientFactory;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tracker\Boursorama\BoursoramaEndOfDayRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoursoramaWatchlistQuoteReaderTest extends TestCase
{
    private const string TRACKER_FIXTURES_DIR = __DIR__.'/../Tracker/Fixtures';
    private const string DCAM_CODE = '1rTDCAM';

    public function testTheQuoteIsReadFromTheRunningSessionAndTheCloseOfTheEve(): void
    {
        $httpClient = BoursoramaHttpClientFactory::serving([], [self::fixtureResponse('boursorama-session-dcam.json')]);

        $quote = self::buildReader($httpClient)->readQuote(self::DCAM_CODE);

        self::assertNotNull($quote);
        self::assertSame(6.175, $quote->price);
        self::assertSame(-5.0, $quote->changePercentage);
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testWithoutASessionTheQuoteFallsBackOnTheLastTwoDailyBars(): void
    {
        $httpClient = BoursoramaHttpClientFactory::serving([self::fixtureResponse('boursorama-ticks-dcam.json')]);

        $quote = self::buildReader($httpClient)->readQuote(self::DCAM_CODE);

        self::assertNotNull($quote);
        self::assertSame(6.262, $quote->price);
        self::assertSame(1.0, $quote->changePercentage);
    }

    public function testASingleDailyBarGivesNoQuoteAndSaysWhy(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = BoursoramaHttpClientFactory::serving([self::fixtureResponse('boursorama-ticks-single-bar.json')]);

        self::assertNull(self::buildReader($httpClient, $logger)->readQuote(self::DCAM_CODE));
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
    }

    public function testAnHttpFailureGivesNoQuoteWithoutThrowing(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = BoursoramaHttpClientFactory::serving([], [new MockResponse('', ['http_code' => 503])]);

        self::assertNull(self::buildReader($httpClient, $logger)->readQuote(self::DCAM_CODE));
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(self::DCAM_CODE, $logger->records[0]['context']['symbol'] ?? null);
    }

    private static function buildReader(MockHttpClient $httpClient, RecordingLoggerStub $logger = new RecordingLoggerStub()): BoursoramaWatchlistQuoteReader
    {
        return new BoursoramaWatchlistQuoteReader(new BoursoramaEndOfDayRequest($httpClient), $logger);
    }

    private static function fixtureResponse(string $fixtureFileName): MockResponse
    {
        return MockResponse::fromFile(self::TRACKER_FIXTURES_DIR.'/'.$fixtureFileName);
    }
}
