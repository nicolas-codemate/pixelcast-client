<?php

declare(strict_types=1);

namespace App\Tests\Client\State;

use App\Client\Inspector\InspectorSnapshot;
use App\Client\Inspector\InspectorTransport;
use App\Client\State\DevDeviceStateSource;
use App\Domain\AppDomain;
use PHPUnit\Framework\TestCase;

final class DevDeviceStateSourceTest extends TestCase
{
    public function testInspectEndpointIsCalledOnceRegardlessOfDomainCount(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $source = new DevDeviceStateSource($stubClient, 'http://example.invalid');

        $source->snapshot();
        $source->getDomainState(AppDomain::Weather);

        self::assertSame(1, $stubClient->fetchCount);
    }

    public function testAnInjectedSnapshotAvoidsAnyTransportCall(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $injectedSnapshot = InspectorSnapshot::fromInspectPayload([
            'state' => ['weather' => ['current' => ['temp' => 18]]],
        ]);
        $source = new DevDeviceStateSource($stubClient, 'http://example.invalid', $injectedSnapshot);

        $snapshot = $source->snapshot();

        self::assertSame(0, $stubClient->fetchCount);
        self::assertTrue($snapshot['weather']->hasData);
    }

    public function testReachabilityErrorExposesTheTransportFailure(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $stubClient->cannedSnapshot = InspectorSnapshot::unreachable('boom');
        $source = new DevDeviceStateSource($stubClient, 'http://example.invalid');

        self::assertSame('boom', $source->reachabilityError());
    }

    public function testGetDomainStateReturnsHasDataFalseWhenUnreachable(): void
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $stubClient->cannedSnapshot = InspectorSnapshot::unreachable('boom');
        $source = new DevDeviceStateSource($stubClient, 'http://example.invalid');

        foreach (AppDomain::cases() as $domain) {
            $state = $source->getDomainState($domain);
            self::assertFalse($state->hasData, "Expected hasData=false for {$domain->value}");
            self::assertNull($state->payload);
        }
    }

    public function testGetDomainStateForWeatherWithCurrentNotNullHasData(): void
    {
        $source = $this->buildSourceWithState(['weather' => ['current' => ['temp' => 20]]]);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertTrue($state->hasData);
        self::assertSame(['current' => ['temp' => 20]], $state->payload);
    }

    public function testGetDomainStateForWeatherWithCurrentNullHasNoData(): void
    {
        $source = $this->buildSourceWithState(['weather' => ['current' => null]]);

        $state = $source->getDomainState(AppDomain::Weather);

        self::assertFalse($state->hasData);
        self::assertSame(['current' => null], $state->payload);
    }

    public function testGetDomainStateForTrackersEmptyListHasNoData(): void
    {
        $source = $this->buildSourceWithState(['trackers' => ['trackers' => [], 'count' => 0]]);

        $state = $source->getDomainState(AppDomain::Trackers);

        self::assertFalse($state->hasData);
    }

    public function testGetDomainStateForTrackersWithItemsHasData(): void
    {
        $source = $this->buildSourceWithState(['trackers' => ['trackers' => [['id' => 1]], 'count' => 1]]);

        $state = $source->getDomainState(AppDomain::Trackers);

        self::assertTrue($state->hasData);
        self::assertSame(['trackers' => [['id' => 1]], 'count' => 1], $state->payload);
    }

    public function testGetDomainStateForCustomAppsEmptyHasNoData(): void
    {
        $source = $this->buildSourceWithState(['customApps' => ['apps' => [], 'count' => 0]]);

        $state = $source->getDomainState(AppDomain::CustomApps);

        self::assertFalse($state->hasData);
    }

    public function testGetDomainStateForCustomAppsWithItemsHasData(): void
    {
        $source = $this->buildSourceWithState(['customApps' => ['apps' => [['id' => 'a1']], 'count' => 1]]);

        $state = $source->getDomainState(AppDomain::CustomApps);

        self::assertTrue($state->hasData);
    }

    public function testGetDomainStateForIndicatorsAllSlotsNullHasNoData(): void
    {
        $source = $this->buildSourceWithState([
            'indicators' => ['slot1' => null, 'slot2' => null, 'slot3' => null],
        ]);

        $state = $source->getDomainState(AppDomain::Indicators);

        self::assertFalse($state->hasData);
    }

    public function testGetDomainStateForIndicatorsAnySlotSetHasData(): void
    {
        $source = $this->buildSourceWithState([
            'indicators' => ['slot1' => null, 'slot2' => ['color' => 'red'], 'slot3' => null],
        ]);

        $state = $source->getDomainState(AppDomain::Indicators);

        self::assertTrue($state->hasData);
    }

    public function testGetDomainStateForNotificationsEmptyQueueHasNoData(): void
    {
        $source = $this->buildSourceWithState(['notifications' => ['queue' => []]]);

        $state = $source->getDomainState(AppDomain::Notifications);

        self::assertFalse($state->hasData);
    }

    public function testGetDomainStateForNotificationsWithItemsHasData(): void
    {
        $source = $this->buildSourceWithState(['notifications' => ['queue' => [['id' => 'x']], 'count' => 1, 'current' => null]]);

        $state = $source->getDomainState(AppDomain::Notifications);

        self::assertTrue($state->hasData);
    }

    public function testGetDomainStateForIconsEmptyHasNoData(): void
    {
        $source = $this->buildSourceWithState(['icons' => ['icons' => [], 'count' => 0]]);

        $state = $source->getDomainState(AppDomain::Icons);

        self::assertFalse($state->hasData);
    }

    public function testSnapshotReturnsAllSixAppDomainKeys(): void
    {
        $source = $this->buildSourceWithState([
            'weather' => ['current' => ['temp' => 18]],
            'trackers' => ['trackers' => [], 'count' => 0],
            'notifications' => ['queue' => [['id' => 'n1']], 'count' => 1, 'current' => null],
            'customApps' => ['apps' => [], 'count' => 0],
            'indicators' => ['slot1' => ['color' => 'red'], 'slot2' => null, 'slot3' => null],
            'icons' => ['icons' => [], 'count' => 0],
        ]);

        $snapshot = $source->snapshot();

        self::assertSame(
            ['weather', 'trackers', 'notifications', 'indicators', 'icons', 'customApps'],
            array_keys($snapshot),
        );
        self::assertTrue($snapshot['weather']->hasData);
        self::assertFalse($snapshot['trackers']->hasData);
        self::assertTrue($snapshot['notifications']->hasData);
        self::assertFalse($snapshot['customApps']->hasData);
        self::assertTrue($snapshot['indicators']->hasData);
        self::assertFalse($snapshot['icons']->hasData);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function buildSourceWithState(array $state): DevDeviceStateSource
    {
        $stubClient = new CountingInspectorHttpClientStub();
        $stubClient->cannedSnapshot = InspectorSnapshot::fromInspectPayload(['state' => $state]);

        return new DevDeviceStateSource($stubClient, 'http://example.invalid');
    }
}

final class CountingInspectorHttpClientStub implements InspectorTransport
{
    public int $fetchCount = 0;
    public ?InspectorSnapshot $cannedSnapshot = null;

    public function fetch(?string $baseUrl): InspectorSnapshot
    {
        ++$this->fetchCount;

        return $this->cannedSnapshot ?? InspectorSnapshot::fromInspectPayload([
            'state' => ['weather' => ['city' => 'Paris']],
            'requests' => [],
        ]);
    }
}
