<?php

declare(strict_types=1);

namespace App\Tests\Tracker\History;

use App\Config\Sync\BoursoramaSyncConfig;
use App\Config\Sync\StaleDeclaration;
use App\Config\Sync\SyncInterval;
use App\Config\Sync\TrackerItem;
use App\Tests\Stub\RecordingLoggerStub;
use App\Tracker\History\BoursoramaAllTimeHighSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoursoramaAllTimeHighSourceTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string BOURSORAMA_BASE_URI = 'https://www.boursorama.com/';
    private const string DEEP_HISTORY_FIXTURE = 'boursorama-ticks-deep-cw8.json';
    private const string CW8_CODE = '1rTCW8';
    private const string CATCH_UP_INSTANT = '2026-08-11 15:00:00';

    public function testTheSyncTypeIsTheOneOfTheBoursoramaGroup(): void
    {
        $source = self::buildSource(self::fixtureClient());

        self::assertSame(BoursoramaSyncConfig::syncType(), $source->syncType());
    }

    public function testTheAllTimeHighIsTheHighestDailyHighOfTheWholeSeries(): void
    {
        $allTimeHigh = self::buildSource(self::fixtureClient())->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertSame(742.8, $allTimeHigh?->price);
        self::assertSame(BoursoramaSyncConfig::syncType(), $allTimeHigh->syncType);
        self::assertSame(self::CW8_CODE, $allTimeHigh->symbol);
        self::assertSame('eur', $allTimeHigh->currency);
        self::assertEquals(self::catchUpInstant(), $allTimeHigh->observedAt);
    }

    public function testTheHighestBarIsFoundWhereverItSitsInTheSeries(): void
    {
        $lastClosingPriceOfTheSeries = 700.18;
        $highestClosingPriceOfTheSeries = 712.4;

        $allTimeHigh = self::buildSource(self::fixtureClient())->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertNotNull($allTimeHigh);
        self::assertGreaterThan($highestClosingPriceOfTheSeries, $allTimeHigh->price);
        self::assertGreaterThan($lastClosingPriceOfTheSeries, $allTimeHigh->price);
    }

    public function testTheDateReachedIsTheDayOfTheHighestBar(): void
    {
        $allTimeHigh = self::buildSource(self::fixtureClient())->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertSame('2022-02-07', $allTimeHigh?->reachedAt?->format('Y-m-d'));
    }

    public function testABarWithoutAHighFallsBackOnItsClose(): void
    {
        $httpClient = self::seriesClient([
            ['d' => 20637, 'o' => 6.0, 'h' => 6.1, 'l' => 5.9, 'c' => 6.05],
            ['d' => 20638, 'o' => 6.05, 'l' => 5.95, 'c' => 6.4],
            ['d' => 20639, 'o' => 6.4, 'h' => 6.3, 'l' => 6.1, 'c' => 6.2],
        ]);

        $allTimeHigh = self::buildSource($httpClient)->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertSame(6.4, $allTimeHigh?->price);
        self::assertSame('2026-07-04', $allTimeHigh->reachedAt?->format('Y-m-d'));
    }

    public function testTheRequestAsksForTwentyYearsOfDailyBars(): void
    {
        $response = self::fixtureResponse();
        $httpClient = new MockHttpClient($response, self::BOURSORAMA_BASE_URI);

        self::buildSource($httpClient)->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertStringStartsWith(self::BOURSORAMA_BASE_URI.'bourse/action/graph/ws/GetTicksEOD?', $response->getRequestUrl());

        $queryParameters = self::queryParameters($response);
        self::assertSame(self::CW8_CODE, $queryParameters['symbol'] ?? null);
        self::assertSame('7300', $queryParameters['length'] ?? null);
        self::assertSame('0', $queryParameters['period'] ?? null);
        self::assertSame('', $queryParameters['guid'] ?? null);
    }

    public function testTheRequestCarriesTheHeadersTheEndpointDemands(): void
    {
        $response = self::fixtureResponse();

        self::buildSource(new MockHttpClient($response, self::BOURSORAMA_BASE_URI))->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        $requestHeaders = self::requestHeaders($response);
        self::assertContains('X-Requested-With: XMLHttpRequest', $requestHeaders);
        self::assertStringContainsString('User-Agent: Mozilla/', implode("\n", $requestHeaders));
    }

    public function testTheRequestRaisesTheTimeoutAboveTheOneOfTheScopedClient(): void
    {
        $scopedClientTimeoutInSeconds = 5;
        $scopedClientMaxDurationInSeconds = 10;
        $response = self::fixtureResponse();

        self::buildSource(new MockHttpClient($response, self::BOURSORAMA_BASE_URI))->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        $requestOptions = $response->getRequestOptions();
        self::assertGreaterThan($scopedClientTimeoutInSeconds, $requestOptions['timeout'] ?? null);
        self::assertGreaterThan($scopedClientMaxDurationInSeconds, $requestOptions['max_duration'] ?? null);
    }

    public function testTheCoveredWindowIsLoggedSoThatItCanBeAudited(): void
    {
        $logger = new RecordingLoggerStub();

        self::buildSource(self::fixtureClient(), $logger)->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertSame(LogLevel::INFO, $logger->records[0]['level'] ?? null);
        self::assertSame(['Boursorama deep history read'], array_column($logger->records, 'message'));
        self::assertSame(self::CW8_CODE, $logger->records[0]['context']['symbol'] ?? null);
        self::assertSame(13, $logger->records[0]['context']['bar_count'] ?? null);
        self::assertSame('2010-02-10', $logger->records[0]['context']['first_bar_day'] ?? null);
    }

    public function testAnEmptySeriesServesNoAllTimeHighAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();

        $allTimeHigh = self::buildSource(self::seriesClient([]), $logger)->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertNull($allTimeHigh);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Boursorama served no usable deep history'], array_column($logger->records, 'message'));
        self::assertSame(self::CW8_CODE, $logger->records[0]['context']['symbol'] ?? null);
    }

    public function testAFailedRequestServesNoAllTimeHighAndLogsAWarning(): void
    {
        $logger = new RecordingLoggerStub();
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']), self::BOURSORAMA_BASE_URI);

        $allTimeHigh = self::buildSource($httpClient, $logger)->fetchAllTimeHigh(self::trackedItem(), self::catchUpInstant());

        self::assertNull($allTimeHigh);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
        self::assertSame(['Boursorama deep history request failed'], array_column($logger->records, 'message'));
        self::assertNotNull($logger->records[0]['context']['error'] ?? null);
    }

    private static function buildSource(MockHttpClient $httpClient, ?LoggerInterface $logger = null): BoursoramaAllTimeHighSource
    {
        return new BoursoramaAllTimeHighSource($httpClient, $logger ?? new NullLogger());
    }

    private static function trackedItem(string $boursoramaCode = self::CW8_CODE, string $currency = 'eur'): TrackerItem
    {
        $groupInterval = SyncInterval::fromOptions(['interval' => '15 minutes'], 'test');

        return new TrackerItem($boursoramaCode, $currency, null, StaleDeclaration::fromOptions([], 'test', $groupInterval, []));
    }

    private static function catchUpInstant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::CATCH_UP_INSTANT);
    }

    private static function fixtureClient(): MockHttpClient
    {
        return new MockHttpClient(self::fixtureResponse(), self::BOURSORAMA_BASE_URI);
    }

    private static function fixtureResponse(): MockResponse
    {
        return MockResponse::fromFile(self::FIXTURES_DIR.'/'.self::DEEP_HISTORY_FIXTURE);
    }

    /**
     * @param list<array<string, float|int>> $quoteBars
     */
    private static function seriesClient(array $quoteBars): MockHttpClient
    {
        $body = json_encode(['d' => ['QuoteTab' => $quoteBars]], \JSON_THROW_ON_ERROR);

        return new MockHttpClient(new MockResponse($body), self::BOURSORAMA_BASE_URI);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function queryParameters(MockResponse $response): array
    {
        $queryString = parse_url($response->getRequestUrl(), \PHP_URL_QUERY);
        if (!\is_string($queryString)) {
            self::fail('The Boursorama request carries no query string.');
        }

        parse_str($queryString, $queryParameters);

        return $queryParameters;
    }

    /**
     * @return list<string>
     */
    private static function requestHeaders(MockResponse $response): array
    {
        $headers = $response->getRequestOptions()['headers'] ?? [];
        if (!\is_array($headers)) {
            self::fail('The Boursorama request carries no readable headers.');
        }

        $stringHeaders = [];
        foreach ($headers as $header) {
            if (\is_string($header)) {
                $stringHeaders[] = $header;
            }
        }

        return $stringHeaders;
    }
}
