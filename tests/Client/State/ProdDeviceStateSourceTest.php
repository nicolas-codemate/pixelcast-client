<?php

declare(strict_types=1);

namespace App\Tests\Client\State;

use App\Client\Http\HttpJsonFetcher;
use App\Client\State\ProdDeviceStateSource;
use App\Client\Transport\IconsTransport;
use App\Client\Transport\NotificationsTransport;
use App\Client\Transport\SettingsTransport;
use App\Client\Transport\TrackersTransport;
use App\Client\Transport\WeatherTransport;
use App\Domain\AppDomain;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class ProdDeviceStateSourceTest extends TestCase
{
    private const string BASE_URL = 'http://device.test/api';
    private const string WEATHER_URL = self::BASE_URL.'/weather';
    private const string TRACKERS_URL = self::BASE_URL.'/trackers';
    private const string NOTIFICATIONS_URL = self::BASE_URL.'/notify/list';
    private const string ICONS_URL = self::BASE_URL.'/icons';
    private const string SETTINGS_URL = self::BASE_URL.'/settings';

    public function testNoRequestIsSentBeforeTheStateIsRead(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $this->buildSource($fetcher);

        self::assertSame([], $fetcher->callCounts);
    }

    public function testEachEndpointIsCalledOnceAcrossRepeatedReads(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $source->snapshot();
        $source->getDomainState(AppDomain::Weather);
        $source->latestSettings();

        self::assertSame(1, $fetcher->callCounts[self::WEATHER_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::TRACKERS_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::NOTIFICATIONS_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::ICONS_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::SETTINGS_URL] ?? 0);
    }

    public function testGetDomainStateWeatherHasDataWhenCurrentNotNull(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::WEATHER_URL] = ['current' => ['temp' => 20]];
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertTrue($state->hasData);
        self::assertSame(['current' => ['temp' => 20]], $state->payload);
    }

    public function testGetDomainStateWeatherHasNoDataWhenCurrentIsNull(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::WEATHER_URL] = ['current' => null];
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertFalse($state->hasData);
        self::assertSame(['current' => null], $state->payload);
    }

    public function testGetDomainStateTrackersEmptyHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::TRACKERS_URL] = ['trackers' => [], 'count' => 0];
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Trackers);

        self::assertFalse($state->hasData);
    }

    public function testGetDomainStateTrackersWithItemsHasData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::TRACKERS_URL] = ['trackers' => [['name' => 'BTC']], 'count' => 1];
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Trackers);

        self::assertTrue($state->hasData);
        self::assertSame(['trackers' => [['name' => 'BTC']], 'count' => 1], $state->payload);
    }

    public function testGetDomainStateNotificationsEmptyHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::NOTIFICATIONS_URL] = ['count' => 0, 'currentIndex' => 0, 'notifications' => []];
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Notifications);

        self::assertFalse($state->hasData);
    }

    public function testGetDomainStateNotificationsHasDataWhenListNonEmpty(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::NOTIFICATIONS_URL] = [
            'count' => 1,
            'currentIndex' => 0,
            'notifications' => [['text' => 'hello']],
        ];
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Notifications);

        self::assertTrue($state->hasData);
    }

    public function testGetDomainStateIconsHasDataWhenListNonEmpty(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::ICONS_URL] = ['icons' => [['name' => 'x']]];
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Icons);

        self::assertTrue($state->hasData);
        self::assertSame(['icons' => [['name' => 'x']]], $state->payload);
    }

    public function testGetDomainStateIndicatorsAlwaysHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Indicators);

        self::assertFalse($state->hasData);
        self::assertNull($state->payload);
        self::assertArrayNotHasKey(self::BASE_URL.'/indicators', $fetcher->callCounts);
    }

    public function testGetDomainStateCustomAppsAlwaysHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::CustomApps);

        self::assertFalse($state->hasData);
        self::assertNull($state->payload);
        self::assertArrayNotHasKey(self::BASE_URL.'/customapps', $fetcher->callCounts);
        self::assertArrayNotHasKey(self::BASE_URL.'/customApps', $fetcher->callCounts);
    }

    public function testSnapshotReturnsAllSixAppDomainKeys(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $snapshot = $source->snapshot();
        $keys = array_keys($snapshot);
        sort($keys);

        $expected = ['customApps', 'icons', 'indicators', 'notifications', 'trackers', 'weather'];
        sort($expected);
        self::assertSame($expected, $keys);
    }

    public function testGetDomainStateReturnsNullPayloadWhenEndpointUnreachable(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertFalse($state->hasData);
        self::assertNull($state->payload);
    }

    public function testReachabilityErrorIsNullWhenAtLeastOneEndpointAnswers(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::WEATHER_URL] = ['current' => ['temp' => 20]];
        $source = $this->buildSource($fetcher);

        self::assertNull($source->reachabilityError());
    }

    public function testReachabilityErrorIsNullWhenOnlySettingsAnswers(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::SETTINGS_URL] = ['BRI' => 100];
        $source = $this->buildSource($fetcher);

        self::assertNull($source->reachabilityError());
    }

    public function testReachabilityErrorExplainsThatNoEndpointAnswered(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $error = $source->reachabilityError();

        self::assertNotNull($error);
        self::assertStringContainsString('REST', $error);
    }

    private function buildSource(StubHttpJsonFetcher $fetcher): ProdDeviceStateSource
    {
        return new ProdDeviceStateSource(
            new WeatherTransport($fetcher),
            new TrackersTransport($fetcher),
            new NotificationsTransport($fetcher),
            new IconsTransport($fetcher),
            new SettingsTransport($fetcher),
            self::BASE_URL,
        );
    }
}

final class StubHttpJsonFetcher extends HttpJsonFetcher
{
    /** @var array<string, array<string, mixed>|null> */
    public array $responses = [];

    /** @var array<string, int> */
    public array $callCounts = [];

    public function __construct()
    {
        parent::__construct(new MockHttpClient(), new NullLogger());
    }

    public function fetchJson(string $url): ?array
    {
        $this->callCounts[$url] = ($this->callCounts[$url] ?? 0) + 1;

        return $this->responses[$url] ?? null;
    }
}
