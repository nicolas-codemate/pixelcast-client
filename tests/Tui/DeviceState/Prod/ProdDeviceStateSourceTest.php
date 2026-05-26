<?php

declare(strict_types=1);

namespace App\Tests\Tui\DeviceState\Prod;

use App\Domain\AppDomain;
use App\Tui\DeviceState\Prod\Http\HttpJsonFetcher;
use App\Tui\DeviceState\Prod\ProdDeviceStateSource;
use App\Tui\DeviceState\Prod\Transport\IconsTransport;
use App\Tui\DeviceState\Prod\Transport\NotificationsTransport;
use App\Tui\DeviceState\Prod\Transport\SettingsTransport;
use App\Tui\DeviceState\Prod\Transport\TrackersTransport;
use App\Tui\DeviceState\Prod\Transport\WeatherTransport;
use PHPUnit\Framework\TestCase;

final class ProdDeviceStateSourceTest extends TestCase
{
    private const string BASE_URL = 'http://device.test';
    private const string WEATHER_URL = self::BASE_URL.'/api/weather';
    private const string TRACKERS_URL = self::BASE_URL.'/api/trackers';
    private const string NOTIFICATIONS_URL = self::BASE_URL.'/api/notify/list';
    private const string ICONS_URL = self::BASE_URL.'/api/icons';
    private const string SETTINGS_URL = self::BASE_URL.'/api/settings';

    public function testRefreshReturnsFalseBeforeInterval(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $result = $source->refresh(1.0);

        self::assertFalse($result);
        self::assertSame([], $fetcher->callCounts);
    }

    public function testRefreshReturnsTrueAfterIntervalElapsed(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $result = $source->refresh(2.5);

        self::assertTrue($result);
        self::assertSame(1, $fetcher->callCounts[self::WEATHER_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::TRACKERS_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::NOTIFICATIONS_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::ICONS_URL] ?? 0);
        self::assertSame(1, $fetcher->callCounts[self::SETTINGS_URL] ?? 0);
    }

    public function testRefreshFiresOncePerAccumulatedInterval(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        $firstResult = $source->refresh(2.5);
        $secondResult = $source->refresh(2.5);

        self::assertTrue($firstResult);
        self::assertTrue($secondResult);
        self::assertSame(2, $fetcher->callCounts[self::WEATHER_URL] ?? 0);
        self::assertSame(2, $fetcher->callCounts[self::TRACKERS_URL] ?? 0);
        self::assertSame(2, $fetcher->callCounts[self::NOTIFICATIONS_URL] ?? 0);
        self::assertSame(2, $fetcher->callCounts[self::ICONS_URL] ?? 0);
        self::assertSame(2, $fetcher->callCounts[self::SETTINGS_URL] ?? 0);
    }

    public function testGetDomainStateReturnsNoDataBeforeAnyRefresh(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);

        foreach (AppDomain::cases() as $domain) {
            $state = $source->getDomainState($domain);
            self::assertFalse($state->hasData, "Expected hasData=false for {$domain->value}");
            self::assertNull($state->payload);
        }
    }

    public function testGetDomainStateWeatherHasDataWhenCurrentNotNull(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::WEATHER_URL] = ['current' => ['temp' => 20]];
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertTrue($state->hasData);
        self::assertSame(['current' => ['temp' => 20]], $state->payload);
    }

    public function testGetDomainStateWeatherHasNoDataWhenCurrentIsNull(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::WEATHER_URL] = ['current' => null];
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertFalse($state->hasData);
        self::assertSame(['current' => null], $state->payload);
    }

    public function testGetDomainStateTrackersEmptyHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::TRACKERS_URL] = ['trackers' => [], 'count' => 0];
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Trackers);

        self::assertFalse($state->hasData);
    }

    public function testGetDomainStateTrackersWithItemsHasData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::TRACKERS_URL] = ['trackers' => [['name' => 'BTC']], 'count' => 1];
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Trackers);

        self::assertTrue($state->hasData);
        self::assertSame(['trackers' => [['name' => 'BTC']], 'count' => 1], $state->payload);
    }

    public function testGetDomainStateNotificationsEmptyHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::NOTIFICATIONS_URL] = ['count' => 0, 'currentIndex' => 0, 'notifications' => []];
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

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
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Notifications);

        self::assertTrue($state->hasData);
    }

    public function testGetDomainStateIconsHasDataWhenListNonEmpty(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $fetcher->responses[self::ICONS_URL] = ['icons' => [['name' => 'x']]];
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Icons);

        self::assertTrue($state->hasData);
        self::assertSame(['icons' => [['name' => 'x']]], $state->payload);
    }

    public function testGetDomainStateIndicatorsAlwaysHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Indicators);

        self::assertFalse($state->hasData);
        self::assertNull($state->payload);
        self::assertArrayNotHasKey(self::BASE_URL.'/api/indicators', $fetcher->callCounts);
    }

    public function testGetDomainStateCustomAppsAlwaysHasNoData(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::CustomApps);

        self::assertFalse($state->hasData);
        self::assertNull($state->payload);
        self::assertArrayNotHasKey(self::BASE_URL.'/api/customapps', $fetcher->callCounts);
        self::assertArrayNotHasKey(self::BASE_URL.'/api/customApps', $fetcher->callCounts);
    }

    public function testSnapshotReturnsAllSixAppDomainKeysAfterRefresh(): void
    {
        $fetcher = new StubHttpJsonFetcher();
        $source = $this->buildSource($fetcher);
        $source->refresh(2.5);

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
        $source->refresh(2.5);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertFalse($state->hasData);
        self::assertNull($state->payload);
    }

    private function buildSource(StubHttpJsonFetcher $fetcher, float $interval = 2.0): ProdDeviceStateSource
    {
        return new ProdDeviceStateSource(
            new WeatherTransport($fetcher),
            new TrackersTransport($fetcher),
            new NotificationsTransport($fetcher),
            new IconsTransport($fetcher),
            new SettingsTransport($fetcher),
            self::BASE_URL,
            $interval,
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
        parent::__construct(0.001);
    }

    public function fetchJson(string $url): ?array
    {
        $this->callCounts[$url] = ($this->callCounts[$url] ?? 0) + 1;

        return $this->responses[$url] ?? null;
    }
}
