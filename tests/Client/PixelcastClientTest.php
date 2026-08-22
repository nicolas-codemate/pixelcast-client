<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\Color;
use App\Client\CustomApp\CustomAppPayload;
use App\Client\Exception\DeviceBusyException;
use App\Client\Exception\DeviceUnreachableException;
use App\Client\Exception\InvalidPayloadException;
use App\Client\Exception\PixelcastClientException;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Gauge\GaugePayload;
use App\Client\Gauge\GaugeRow;
use App\Client\Icon\IconUpload;
use App\Client\Indicator\IndicatorMode;
use App\Client\Indicator\IndicatorPayload;
use App\Client\Notification\NotificationPayload;
use App\Client\PixelcastClient;
use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\NtpSettings;
use App\Client\Settings\SettingsPayload;
use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepSlot;
use App\Client\StaleBehavior;
use App\Client\Tracker\TrackerPayload;
use App\Client\Weather\CurrentWeather;
use App\Client\Weather\ForecastDay;
use App\Client\Weather\WeatherIcon;
use App\Client\Weather\WeatherPayload;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use League\OpenAPIValidation\PSR7\RequestValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PixelcastClientTest extends TestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://device.test/api';
    private const string EXPECTED_WEATHER_URL = 'http://device.test/api/weather';
    private const string EXPECTED_TRACKER_URL = 'http://device.test/api/tracker?name=BTC';
    private const string EXPECTED_GAUGE_URL = 'http://device.test/api/gauge?name=claude';
    private const string EXPECTED_CUSTOM_APP_URL = 'http://device.test/api/custom?name=demo';
    private const string EXPECTED_NOTIFY_URL = 'http://device.test/api/notify';
    private const string EXPECTED_DISMISS_URL = 'http://device.test/api/notify/dismiss';
    private const string EXPECTED_SLEEP_URL = 'http://device.test/api/sleep';
    private const string EXPECTED_SETTINGS_URL = 'http://device.test/api/settings';
    private const string EXPECTED_BRIGHTNESS_URL = 'http://device.test/api/brightness';
    private const string EXPECTED_STATS_URL = 'http://device.test/api/stats';
    private const string EXPECTED_REBOOT_URL = 'http://device.test/api/reboot';
    private const string EXPECTED_ICONS_URL = 'http://device.test/api/icons';
    private const string EXPECTED_ICON_UPLOAD_URL = 'http://device.test/api/icons?name=bitcoin';
    private const string EXPECTED_ICON_DELETE_URL = 'http://device.test/api/icons?name=bitcoin';
    private const string EXPECTED_LAMETRIC_ICON_URL = 'http://device.test/api/icons/lametric';
    private const array FIRMWARE_DAY_NAMES = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    // The "scheduleActive" example of sync/openapi.yaml.
    private const string SCHEDULE_ACTIVE_SLEEP_BODY = '{"sleeping":true,"reason":"schedule","config":{"enabled":true,"display_mode":"black","schedule":{"monday":{"all_day":false,"slots":[{"start":"20:00","end":"07:00"}]}},"sleep_until":0}}';

    private const string SETTINGS_RESPONSE_BODY = '{"brightness":128,"autoRotate":true,"defaultDuration":10000,"weatherDuration":12000,"display":{"width":64,"height":8},"ntp":{"server":"pool.ntp.org","tz_posix":"CET-1CEST,M3.5.0,M10.5.0/3"},"mqtt":{"enabled":false,"prefix":"pixelcast"}}';

    private const string ICONS_RESPONSE_BODY = '{"icons":[{"name":"bitcoin","filename":"bitcoin.png","size":171},{"name":"claude","filename":"claude.png","size":107}],"count":2,"storage":{"used":81920,"total":196608}}';

    private const string PNG_CONTENTS = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDRpixels";

    private const string STATS_RESPONSE_BODY = '{"version":"0.1.0-dev","uptime":4213,"freeHeap":142360,"maxAllocHeap":81920,"brightness":128,"wifi":{"ssid":"home","rssi":-45,"ip":"192.168.1.174"},"display":{"width":64,"height":8}}';

    // Parsing the vendored spec costs more than every test of this class combined.
    private static RequestValidator $requestValidator;

    private OutboundPayloadValidator $outboundPayloadValidator;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$requestValidator = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 2), self::TEST_DEVICE_BASE_URL)->create();
    }

    /**
     * @return iterable<string, array{int, string, class-string<PixelcastClientException>, string}>
     */
    public static function failureStatusProvider(): iterable
    {
        yield 'bad request carries the device message' => [400, '{"error":"bad temp"}', InvalidPayloadException::class, '/bad temp/'];
        yield 'not found names the path' => [404, '{"error":"unknown route"}', ResourceNotFoundException::class, '#/weather#'];
        yield 'no free slot' => [500, '{"error":"no free slot"}', DeviceBusyException::class, '/500/'];
        yield 'queue full' => [503, '{"error":"queue full"}', DeviceBusyException::class, '/503/'];
        yield 'unmapped status' => [418, 'teapot', DeviceUnreachableException::class, '/418/'];
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function indicatorSlotProvider(): iterable
    {
        yield 'first corner' => [1, 'http://device.test/api/indicator1'];
        yield 'second corner' => [2, 'http://device.test/api/indicator2'];
        yield 'third corner' => [3, 'http://device.test/api/indicator3'];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function indicatorSlotOutOfRangeProvider(): iterable
    {
        yield 'below the first corner' => [0];
        yield 'above the last corner' => [4];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function busyStatusProvider(): iterable
    {
        yield 'slot exhausted' => [500];
        yield 'queue full' => [503];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->outboundPayloadValidator = $this->buildOutboundPayloadValidator(self::TEST_DEVICE_BASE_URL);
    }

    public function testSuccessfulPushSendsTheSerializedPayloadToTheWeatherEndpoint(): void
    {
        $response = new MockResponse('{"success":true}');
        $payload = self::buildPayload();

        $this->buildClient($response)->pushWeather($payload);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_WEATHER_URL, $response->getRequestUrl());
        self::assertSame($payload->toArray(), self::decodedRequestBody($response));
    }

    public function testPushTrackerSendsTheNameAsQueryParameterAndTheRestAsBody(): void
    {
        $response = new MockResponse('{"success":true}');
        $tracker = self::buildTrackerPayload();

        $this->buildClient($response)->pushTracker($tracker);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_TRACKER_URL, $response->getRequestUrl());
        $sentBody = self::decodedRequestBody($response);

        self::assertSame($tracker->toArray(), $sentBody);
        self::assertArrayNotHasKey('name', $sentBody);
    }

    public function testDeleteTrackerSendsNoBody(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->deleteTracker('BTC');

        self::assertSame('DELETE', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_TRACKER_URL, $response->getRequestUrl());
        self::assertNull($response->getRequestOptions()['body'] ?? null);
    }

    public function testPushGaugeSendsTheNameAsQueryParameterAndTheRestAsBody(): void
    {
        $response = new MockResponse('{"success":true}');
        $gauge = self::buildGaugePayload();

        $this->buildClient($response)->pushGauge($gauge);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_GAUGE_URL, $response->getRequestUrl());
        $sentBody = self::decodedRequestBody($response);

        self::assertSame($gauge->toArray(), $sentBody);
        self::assertArrayNotHasKey('name', $sentBody);
    }

    public function testPushCustomAppSendsTheNameAsQueryParameterAndTheRestAsBody(): void
    {
        $response = new MockResponse('{"success":true}');
        $customApp = self::buildSingleZoneCustomAppPayload();

        $this->buildClient($response)->pushCustomApp($customApp);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_CUSTOM_APP_URL, $response->getRequestUrl());
        $sentBody = self::decodedRequestBody($response);

        self::assertSame($customApp->toArray(), $sentBody);
        self::assertArrayNotHasKey('name', $sentBody);
    }

    public function testDeleteCustomAppSendsNoBody(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->deleteCustomApp('demo');

        self::assertSame('DELETE', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_CUSTOM_APP_URL, $response->getRequestUrl());
        self::assertNull($response->getRequestOptions()['body'] ?? null);
    }

    public function testPushNotificationSendsTheSerializedPayload(): void
    {
        $response = new MockResponse('{"success":true}');
        $notification = self::buildNotificationPayload();

        $this->buildClient($response)->pushNotification($notification);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_NOTIFY_URL, $response->getRequestUrl());
        self::assertSame($notification->toArray(), self::decodedRequestBody($response));
    }

    public function testDismissNotificationSendsABodylessPost(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->dismissNotification();

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_DISMISS_URL, $response->getRequestUrl());
        self::assertNull($response->getRequestOptions()['body'] ?? null);
    }

    public function testPushSleepConfigurationSendsEveryDayAndNeverTheManualSleep(): void
    {
        $response = new MockResponse('{"success":true}');
        $sleep = self::buildSleepPayload();

        $this->buildClient($response)->pushSleepConfiguration($sleep);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_SLEEP_URL, $response->getRequestUrl());
        $sentBody = self::decodedRequestBody($response);

        self::assertSame($sleep->toArray(), $sentBody);
        self::assertArrayNotHasKey('sleep_until', $sentBody);
    }

    public function testFetchSleepStateReadsTheStateAndTheScheduleTheDeviceHolds(): void
    {
        $response = new MockResponse(self::SCHEDULE_ACTIVE_SLEEP_BODY);

        $sleepState = $this->buildClient($response)->fetchSleepState();

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_SLEEP_URL, $response->getRequestUrl());
        self::assertTrue($sleepState->sleeping);
        self::assertSame('schedule', $sleepState->reason);
        self::assertSame('black', $sleepState->displayMode);
        self::assertSame(['monday'], array_keys($sleepState->sleepScheduleByDayName));

        $mondaySlots = $sleepState->sleepScheduleByDayName['monday']->sleepSlots;
        self::assertCount(1, $mondaySlots);
        self::assertSame('20:00', $mondaySlots[0]->start);
        self::assertSame('07:00', $mondaySlots[0]->end);
    }

    public function testFetchSleepStateRefusesABodyThatIsNotAJsonObject(): void
    {
        $client = $this->buildClient(new MockResponse('device is rebooting'));

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessageMatches('/not a JSON object/');

        $client->fetchSleepState();
    }

    public function testPushSettingsSendsOnlyTheFieldThatWasSet(): void
    {
        $response = new MockResponse('{"success":true}');
        $settings = SettingsPayload::create(defaultDurationMilliseconds: 5000);

        $this->buildClient($response)->pushSettings($settings);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_SETTINGS_URL, $response->getRequestUrl());
        self::assertSame(['defaultDuration' => 5000], self::decodedRequestBody($response));
    }

    public function testPushSettingsSendsEveryFieldIncludingTheNtpNode(): void
    {
        $response = new MockResponse('{"success":true}');
        $settings = SettingsPayload::create(
            brightness: BrightnessLevel::create(64),
            autoRotate: false,
            defaultDurationMilliseconds: 8000,
            weatherDurationMilliseconds: 12000,
            ntp: NtpSettings::create(server: 'pool.ntp.org', timezonePosix: 'CET-1CEST,M3.5.0,M10.5.0/3'),
        );

        $this->buildClient($response)->pushSettings($settings);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_SETTINGS_URL, $response->getRequestUrl());
        self::assertSame($settings->toArray(), self::decodedRequestBody($response));
    }

    public function testPushBrightnessSendsTheLevelAlone(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->pushBrightness(BrightnessLevel::create(200));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_BRIGHTNESS_URL, $response->getRequestUrl());
        self::assertSame(['brightness' => 200], self::decodedRequestBody($response));
    }

    public function testRebootSendsABodylessPost(): void
    {
        $response = new MockResponse('{"success":true,"message":"Rebooting..."}');

        $this->buildClient($response)->reboot();

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_REBOOT_URL, $response->getRequestUrl());
        self::assertNull($response->getRequestOptions()['body'] ?? null);
    }

    public function testFetchSettingsReadsTheSettingsTheDeviceHolds(): void
    {
        $response = new MockResponse(self::SETTINGS_RESPONSE_BODY);

        $settings = $this->buildClient($response)->fetchSettings();

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_SETTINGS_URL, $response->getRequestUrl());
        self::assertSame(128, $settings->brightness);
        self::assertTrue($settings->autoRotate);
        self::assertSame(10000, $settings->defaultDurationMilliseconds);
        self::assertSame(12000, $settings->weatherDurationMilliseconds);
        self::assertSame('pool.ntp.org', $settings->ntpServer);
        self::assertSame('CET-1CEST,M3.5.0,M10.5.0/3', $settings->ntpTimezonePosix);
    }

    public function testFetchStatsReadsTheFirmwareTheMemoryAndTheWifiLink(): void
    {
        $response = new MockResponse(self::STATS_RESPONSE_BODY);

        $stats = $this->buildClient($response)->fetchStats();

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_STATS_URL, $response->getRequestUrl());
        self::assertSame('0.1.0-dev', $stats->firmwareVersion);
        self::assertSame(4213, $stats->uptimeSeconds);
        self::assertSame(142360, $stats->freeHeapBytes);
        self::assertSame(81920, $stats->maxAllocatableHeapBytes);
        self::assertSame(128, $stats->brightness);
        self::assertNotNull($stats->wifi);
        self::assertSame('home', $stats->wifi->ssid);
        self::assertSame(-45, $stats->wifi->signalStrengthDbm);
        self::assertSame('192.168.1.174', $stats->wifi->ipAddress);
    }

    public function testListIconsReadsTheIconsAndTheFilesystemOccupancy(): void
    {
        $response = new MockResponse(self::ICONS_RESPONSE_BODY);

        $icons = $this->buildClient($response)->listIcons();

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_ICONS_URL, $response->getRequestUrl());
        self::assertSame(['bitcoin', 'claude'], $icons->iconNames());
        self::assertNotNull($icons->storage);
        self::assertSame(114_688, $icons->storage->availableBytes());
    }

    public function testUploadIconPostsTheFileAsMultipartWithTheNameAsQueryParameter(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->uploadIcon(IconUpload::fromContents('bitcoin', self::PNG_CONTENTS));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_ICON_UPLOAD_URL, $response->getRequestUrl());

        $sentBody = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($sentBody);
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="bitcoin.png"', $sentBody);
        self::assertStringContainsString('Content-Type: image/png', $sentBody);
        self::assertStringContainsString(self::PNG_CONTENTS, $sentBody);
        self::assertNotEmpty(self::multipartContentTypeHeaders($response));
    }

    public function testUploadIconRejectsAFormatTheDeviceDoesNotAcceptBeforeSendingAnything(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessageMatches('/neither a PNG nor a GIF/');

        IconUpload::fromContents('bitcoin', "\xff\xd8\xffJFIF");
    }

    public function testDeleteIconSendsTheNameAsQueryParameter(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->deleteIcon('bitcoin');

        self::assertSame('DELETE', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_ICON_DELETE_URL, $response->getRequestUrl());
    }

    public function testDeletingAnUnknownIconThrowsResourceNotFoundException(): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"Icon not found"}', ['http_code' => 404]));

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('#/icons#');

        $client->deleteIcon('bitcoin');
    }

    public function testDownloadLaMetricIconSendsTheIdentifierAndTheChosenLocalName(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->downloadLaMetricIcon(2867, 'bitcoin');

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::EXPECTED_LAMETRIC_ICON_URL, $response->getRequestUrl());
        self::assertSame(['id' => 2867, 'name' => 'bitcoin'], self::decodedRequestBody($response));
    }

    public function testDownloadLaMetricIconOmitsTheNameSoTheDeviceFallsBackOnTheIdentifier(): void
    {
        $response = new MockResponse('{"success":true}');

        $this->buildClient($response)->downloadLaMetricIcon(2867);

        self::assertSame(['id' => 2867], self::decodedRequestBody($response));
    }

    #[DataProvider('indicatorSlotProvider')]
    public function testSetIndicatorSendsThePayloadToTheAddressedSlot(int $slot, string $expectedUrl): void
    {
        $response = new MockResponse('{"success":true,"indicator":'.$slot.',"mode":"blink","color":"#FF0000"}');
        $payload = IndicatorPayload::create(IndicatorMode::Blink, Color::fromHexCode('#FF0000'), blinkIntervalMilliseconds: 500);

        $this->buildClient($response)->setIndicator($slot, $payload);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame($expectedUrl, $response->getRequestUrl());
        self::assertSame($payload->toArray(), self::decodedRequestBody($response));
    }

    #[DataProvider('indicatorSlotProvider')]
    public function testClearIndicatorDeletesTheAddressedSlot(int $slot, string $expectedUrl): void
    {
        $response = new MockResponse('{"success":true,"mode":"off"}');

        $this->buildClient($response)->clearIndicator($slot);

        self::assertSame('DELETE', $response->getRequestMethod());
        self::assertSame($expectedUrl, $response->getRequestUrl());
    }

    #[DataProvider('indicatorSlotOutOfRangeProvider')]
    public function testSetIndicatorRefusesASlotOutsideTheThreeCornersBeforeSendingAnything(int $slot): void
    {
        $this->assertIndicatorSlotIsRefusedBeforeSending(
            static function (PixelcastClient $client) use ($slot): void {
                $client->setIndicator($slot, IndicatorPayload::create(IndicatorMode::Solid, Color::fromHexCode('#00FF00')));
            },
        );
    }

    #[DataProvider('indicatorSlotOutOfRangeProvider')]
    public function testClearIndicatorRefusesASlotOutsideTheThreeCornersBeforeSendingAnything(int $slot): void
    {
        $this->assertIndicatorSlotIsRefusedBeforeSending(
            static function (PixelcastClient $client) use ($slot): void {
                $client->clearIndicator($slot);
            },
        );
    }

    public function testFetchStatsRefusesABodyThatIsNotAJsonObject(): void
    {
        $client = $this->buildClient(new MockResponse('device is rebooting'));

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessageMatches('/not a JSON object/');

        $client->fetchStats();
    }

    public function testLocallyRejectedPayloadIsNotSentAtAll(): void
    {
        // A device base URL without the /api prefix builds a path the spec does not declare.
        $misconfiguredClient = new MockHttpClient(new MockResponse('{"success":true}'), self::TEST_DEVICE_BASE_URL.'/');
        $client = new PixelcastClient($misconfiguredClient, $this->buildOutboundPayloadValidator('http://device.test'));

        try {
            $client->pushWeather(self::buildPayload());
        } catch (InvalidPayloadException $invalidPayload) {
            self::assertStringContainsString('rejected before sending', $invalidPayload->getMessage());
            self::assertSame(0, $misconfiguredClient->getRequestsCount());

            return;
        }

        self::fail('The client sent a payload its own validator rejects.');
    }

    /**
     * @param class-string<PixelcastClientException> $expectedException
     */
    #[DataProvider('failureStatusProvider')]
    public function testFailureStatusMapsToADedicatedException(int $httpStatus, string $responseBody, string $expectedException, string $expectedMessagePattern): void
    {
        $client = $this->buildClient(new MockResponse($responseBody, ['http_code' => $httpStatus]));

        $this->expectException($expectedException);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $client->pushWeather(self::buildPayload());
    }

    #[DataProvider('busyStatusProvider')]
    public function testDeviceBusyExceptionKeepsTheHttpStatusApart(int $httpStatus): void
    {
        $client = $this->buildClient(new MockResponse('{"error":"busy"}', ['http_code' => $httpStatus]));

        try {
            $client->pushWeather(self::buildPayload());
        } catch (DeviceBusyException $deviceBusy) {
            self::assertSame($httpStatus, $deviceBusy->httpStatus);

            return;
        }

        self::fail(\sprintf('The client accepted an HTTP %d answer.', $httpStatus));
    }

    public function testTransportFailureThrowsDeviceUnreachableException(): void
    {
        $client = $this->buildClient(new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(DeviceUnreachableException::class);
        $this->expectExceptionMessageMatches('/connection refused/');

        $client->pushWeather(self::buildPayload());
    }

    /**
     * @param \Closure(PixelcastClient): void $callOnTheClient
     */
    private function assertIndicatorSlotIsRefusedBeforeSending(\Closure $callOnTheClient): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"success":true}'), self::TEST_DEVICE_BASE_URL.'/');

        try {
            $callOnTheClient(new PixelcastClient($httpClient, $this->outboundPayloadValidator));
        } catch (\InvalidArgumentException $invalidSlot) {
            self::assertStringContainsString('between 1 and 3', $invalidSlot->getMessage());
            self::assertSame(0, $httpClient->getRequestsCount());

            return;
        }

        self::fail('The client addressed an indicator slot the device does not carry.');
    }

    private function buildClient(MockResponse $response): PixelcastClient
    {
        return new PixelcastClient(
            new MockHttpClient($response, self::TEST_DEVICE_BASE_URL.'/'),
            $this->outboundPayloadValidator,
        );
    }

    private function buildOutboundPayloadValidator(string $deviceBaseUrl): OutboundPayloadValidator
    {
        return new OutboundPayloadValidator(self::$requestValidator, new Psr17Factory(), $deviceBaseUrl);
    }

    private static function buildPayload(): WeatherPayload
    {
        return new WeatherPayload(
            new CurrentWeather(WeatherIcon::Rain, 9, -2, 14, 80),
            [new ForecastDay('LUN', WeatherIcon::Cloudy, 4, 12)],
            staleAfterInSeconds: 5400,
            staleBehavior: StaleBehavior::Hide,
        );
    }

    private static function buildTrackerPayload(): TrackerPayload
    {
        return new TrackerPayload(
            name: 'BTC',
            symbol: 'BTC',
            iconName: 'bitcoin',
            currency: '$',
            currentValue: 67890.5,
            changePercentage: -1.25,
            sparklinePoints: [67000.0, 67500.0, 67890.5],
            symbolColor: Color::fromHexCode('#FF8800'),
            sparklineColor: Color::fromHexCode('#00D4FF'),
            bottomText: 'Bitcoin',
            staleAfterInSeconds: 2700,
            staleBehavior: StaleBehavior::Dim,
        );
    }

    private static function buildGaugePayload(): GaugePayload
    {
        return GaugePayload::create(
            name: 'claude',
            rows: [
                GaugeRow::create(
                    label: '5h',
                    percent: 41,
                    info: '14:50',
                    value: '41%',
                    note: 'x1.2^',
                    barColor: Color::fromHexCode('#4CAF50'),
                    noteColor: Color::fromHexCode('#FFC107'),
                ),
                GaugeRow::create(label: 'credits', percent: 1, value: '1%'),
            ],
            title: 'Claude',
            iconName: 'claude',
            displayDurationMilliseconds: 10000,
            staleAfterInSeconds: 2700,
            staleBehavior: StaleBehavior::Dim,
        );
    }

    private static function buildSingleZoneCustomAppPayload(): CustomAppPayload
    {
        return CustomAppPayload::createSingleZone(
            name: 'demo',
            text: 'Hello World',
            iconName: 'smiley',
            label: 'Demo',
            color: Color::fromHexCode('#FF8800'),
            displayDurationMilliseconds: 10000,
            staleAfterInSeconds: 3600,
            staleBehavior: StaleBehavior::Hide,
        );
    }

    private static function buildNotificationPayload(): NotificationPayload
    {
        return new NotificationPayload(
            text: 'Build finished',
            iconName: 'check',
            textColor: Color::fromHexCode('#0096FF'),
            holdUntilDismissed: true,
            urgent: true,
        );
    }

    private static function buildSleepPayload(): SleepPayload
    {
        return new SleepPayload(
            enabled: true,
            displayMode: 'black',
            sleepSlotsByDayName: array_fill_keys(self::FIRMWARE_DAY_NAMES, [new SleepSlot('00:00', '07:00')]),
        );
    }

    /**
     * @return list<string>
     */
    private static function multipartContentTypeHeaders(MockResponse $response): array
    {
        $sentHeaders = $response->getRequestOptions()['headers'] ?? null;

        if (!\is_array($sentHeaders)) {
            self::fail('The mock HTTP client received no request header.');
        }

        return array_values(array_filter(
            $sentHeaders,
            static fn (mixed $header): bool => \is_string($header) && str_contains($header, 'multipart/form-data; boundary='),
        ));
    }

    private static function decodedRequestBody(MockResponse $response): mixed
    {
        $encodedRequestBody = $response->getRequestOptions()['body'] ?? null;

        if (!\is_string($encodedRequestBody)) {
            self::fail('The mock HTTP client received no string request body.');
        }

        return json_decode($encodedRequestBody, true);
    }
}
