<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Client\Color;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Notification\NotificationPayload;
use App\Client\Tracker\TrackerPayload;
use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Simulator\State\PersistedStateReader;

final class PixelcastClientHttpTest extends SimulatorHttpTestCase
{
    public function testPushedWeatherPayloadIsTheOneReceivedBySimulator(): void
    {
        $payload = new WeatherPayload(
            new CurrentWeather(WeatherIcon::HeavyRain, 9, -2, 14, 80),
            [
                new ForecastDay('LUN', WeatherIcon::Cloudy, 4, 12),
                new ForecastDay('MAR', WeatherIcon::Snow, -3, 2),
            ],
        );

        $this->buildPixelcastClient()->pushWeather($payload);

        $this->assertLoggedRequest($this->inspect(), 0, 1, 'POST', '/api/weather', $payload->toArray());
    }

    public function testPushedTrackerIsStoredUnderItsNameAndDeletedAfterwards(): void
    {
        $tracker = new TrackerPayload(
            name: 'BTC',
            symbol: 'BTC',
            iconName: 'bitcoin',
            currency: 'USD',
            currentValue: 98452.30,
            changePercentage: 2.14,
            sparklinePoints: [92100.5, 89300.25, 93200.75],
            symbolColor: Color::fromHexCode('#FF8800'),
            sparklineColor: Color::fromHexCode('#00D4FF'),
            bottomText: 'Vol 24h: 42B',
        );
        $client = $this->buildPixelcastClient();

        $client->pushTracker($tracker);

        $inspectionAfterPush = $this->inspect();
        $this->assertLoggedRequest($inspectionAfterPush, 0, 1, 'POST', '/api/tracker', $tracker->toArray());
        self::assertSame(['BTC'], self::storedTrackerNames($inspectionAfterPush), $this->server->serverOutput());

        $client->deleteTracker('BTC');

        $inspectionAfterDelete = $this->inspect();
        $this->assertLoggedRequest($inspectionAfterDelete, 1, 2, 'DELETE', '/api/tracker', null);
        self::assertSame([], self::storedTrackerNames($inspectionAfterDelete), $this->server->serverOutput());
    }

    public function testTrackerWithoutAnyOptionalFieldIsAcceptedAsAnEmptyJsonBody(): void
    {
        $this->buildPixelcastClient()->pushTracker(new TrackerPayload('BTC'));

        $inspection = $this->inspect();
        $this->assertLoggedRequest($inspection, 0, 1, 'POST', '/api/tracker', []);
        self::assertSame(['BTC'], self::storedTrackerNames($inspection), $this->server->serverOutput());
    }

    public function testPushedNotificationIsQueuedThenDismissed(): void
    {
        $notification = new NotificationPayload(
            text: 'Deploy finished',
            iconName: 'rocket',
            textColor: Color::fromHexCode('#00D4FF'),
            holdUntilDismissed: true,
            urgent: true,
        );
        $client = $this->buildPixelcastClient();

        $client->pushNotification($notification);

        $inspectionAfterPush = $this->inspect();
        $this->assertLoggedRequest($inspectionAfterPush, 0, 1, 'POST', '/api/notify', $notification->toArray());
        self::assertSame(1, self::domainState($inspectionAfterPush, 'notifications')['count'] ?? null, $this->server->serverOutput());

        $client->dismissNotification();

        $inspectionAfterDismissal = $this->inspect();
        $this->assertLoggedRequest($inspectionAfterDismissal, 1, 2, 'POST', '/api/notify/dismiss', null);
        self::assertSame(0, self::domainState($inspectionAfterDismissal, 'notifications')['count'] ?? null, $this->server->serverOutput());
    }

    public function testDismissingWithoutAnyNotificationThrowsResourceNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->buildPixelcastClient()->dismissNotification();
    }

    /**
     * @param array<string, mixed> $inspectPayload
     *
     * @return list<string>
     */
    private static function storedTrackerNames(array $inspectPayload): array
    {
        $trackerState = self::domainState($inspectPayload, 'trackers');

        return array_keys(PersistedStateReader::payloadMap($trackerState['trackers'] ?? null));
    }
}
