<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Client\Color;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Gauge\GaugePayload;
use App\Client\Gauge\GaugeRow;
use App\Client\Icon\IconUpload;
use App\Client\Notification\NotificationPayload;
use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\SettingsPayload;
use App\Client\StaleBehavior;
use App\Client\Tracker\TrackerPayload;
use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Simulator\State\PersistedStateReader;
use Symfony\Component\HttpFoundation\Response;

final class PixelcastClientHttpTest extends SimulatorHttpTestCase
{
    private const string PNG_CONTENTS = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDRpixels";

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

    public function testPushedGaugeIsStoredUnderItsName(): void
    {
        $gauge = self::buildGaugePayload([
            GaugeRow::create(
                label: '5h',
                percent: 41,
                info: '14:50',
                value: '41%',
                note: 'x1.2^',
                barColor: Color::fromHexCode('#4CAF50'),
                noteColor: Color::fromHexCode('#FFC107'),
            ),
            GaugeRow::create(label: 'credits', percent: 1, value: '1%', barColor: Color::fromHexCode('#4CAF50')),
        ]);

        $this->buildPixelcastClient()->pushGauge($gauge);

        $inspection = $this->inspect();
        $this->assertLoggedRequest($inspection, 0, 1, 'POST', '/api/gauge', $gauge->toArray());
        self::assertSame(['claude'], self::storedGaugeNames($inspection), $this->server->serverOutput());
    }

    public function testASecondGaugePushReplacesTheStoredRowsWhole(): void
    {
        $client = $this->buildPixelcastClient();
        $client->pushGauge(self::buildGaugePayload([
            GaugeRow::create(label: '5h', percent: 41),
            GaugeRow::create(label: '7j', percent: 28),
        ]));

        $replacement = self::buildGaugePayload([GaugeRow::create(label: 'credits', percent: 1)]);
        $client->pushGauge($replacement);

        $inspection = $this->inspect();
        $this->assertLoggedRequest($inspection, 1, 2, 'POST', '/api/gauge', $replacement->toArray());
        self::assertSame(
            [['label' => 'credits', 'percent' => 1]],
            self::storedGauges($inspection)['claude']['rows'] ?? null,
            $this->server->serverOutput(),
        );
    }

    public function testGaugeListReportsTheStoredGauge(): void
    {
        $this->buildPixelcastClient()->pushGauge(self::buildGaugePayload([GaugeRow::create(label: '5h', percent: 41)]));

        $listResponse = $this->server->get('/api/gauges');

        self::assertSame(Response::HTTP_OK, $listResponse->statusCode, $this->explain($listResponse));
        $decodedList = $listResponse->decodedBody();
        self::assertSame(1, $decodedList['count'] ?? null);
        self::assertSame(
            [['name' => 'claude', 'title' => 'Claude', 'rowCount' => 1]],
            $decodedList['gauges'] ?? null,
        );
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

    public function testDeletingACustomAppThatWasNeverPushedThrowsResourceNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->buildPixelcastClient()->deleteCustomApp('github');
    }

    public function testPartialSettingsUpdateLeavesTheOtherSettingsUntouched(): void
    {
        $client = $this->buildPixelcastClient();

        $client->pushSettings(SettingsPayload::create(brightness: BrightnessLevel::create(42)));

        $this->assertLoggedRequest($this->inspect(), 0, 1, 'POST', '/api/settings', ['brightness' => 42]);

        $storedSettings = $client->fetchSettings();

        self::assertSame(42, $storedSettings->brightness, $this->server->serverOutput());
        self::assertTrue($storedSettings->autoRotate);
        self::assertSame(10000, $storedSettings->defaultDurationMilliseconds);
    }

    public function testStatsReportThePushedBrightnessAndTheWifiLink(): void
    {
        $client = $this->buildPixelcastClient();

        $client->pushBrightness(BrightnessLevel::create(17));

        $stats = $client->fetchStats();

        self::assertSame(17, $stats->brightness, $this->server->serverOutput());
        self::assertSame(81920, $stats->maxAllocatableHeapBytes);
        self::assertNotNull($stats->wifi);
        self::assertSame('simulator', $stats->wifi->ssid);
        self::assertSame(-45, $stats->wifi->signalStrengthDbm);
    }

    public function testUploadedIconIsListedThenDeleted(): void
    {
        $client = $this->buildPixelcastClient();

        $client->uploadIcon(IconUpload::fromContents('bitcoin', self::PNG_CONTENTS));

        $iconsAfterUpload = $client->listIcons();
        self::assertSame(['bitcoin'], $iconsAfterUpload->iconNames(), $this->server->serverOutput());
        self::assertSame(1, $iconsAfterUpload->count);
        self::assertNotNull($iconsAfterUpload->storage);
        self::assertGreaterThan(0, $iconsAfterUpload->storage->totalBytes);

        $client->deleteIcon('bitcoin');

        self::assertSame([], $client->listIcons()->iconNames(), $this->server->serverOutput());
    }

    public function testDeletingAnIconTheDeviceDoesNotCarryThrowsResourceNotFoundException(): void
    {
        $client = $this->buildPixelcastClient();

        $this->expectException(ResourceNotFoundException::class);

        $client->deleteIcon('bitcoin');
    }

    public function testLaMetricIconIsDownloadedByTheDeviceAndAppearsInTheListing(): void
    {
        $client = $this->buildPixelcastClient();

        $client->downloadLaMetricIcon(2867);

        self::assertSame(['lametric_2867'], $client->listIcons()->iconNames(), $this->server->serverOutput());
    }

    public function testRebootIsAcceptedByTheDevice(): void
    {
        $this->buildPixelcastClient()->reboot();

        $this->assertLoggedRequest($this->inspect(), 0, 1, 'POST', '/api/reboot', null);
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

    /**
     * @param array<string, mixed> $inspectPayload
     *
     * @return list<string>
     */
    private static function storedGaugeNames(array $inspectPayload): array
    {
        return array_keys(self::storedGauges($inspectPayload));
    }

    /**
     * @param array<string, mixed> $inspectPayload
     *
     * @return array<string, array<string, mixed>>
     */
    private static function storedGauges(array $inspectPayload): array
    {
        $gaugeState = self::domainState($inspectPayload, 'gauges');

        return PersistedStateReader::payloadMap($gaugeState['gauges'] ?? null);
    }

    /**
     * @param list<GaugeRow> $rows
     */
    private static function buildGaugePayload(array $rows): GaugePayload
    {
        return GaugePayload::create(
            name: 'claude',
            rows: $rows,
            title: 'Claude',
            iconName: 'claude',
            displayDurationMilliseconds: 10000,
            staleAfterInSeconds: 2700,
            staleBehavior: StaleBehavior::Dim,
        );
    }
}
