<?php

declare(strict_types=1);

namespace App\Client;

use App\Client\CustomApp\CustomAppPayload;
use App\Client\Exception\DeviceBusyException;
use App\Client\Exception\DeviceUnreachableException;
use App\Client\Exception\InvalidPayloadException;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Gauge\GaugePayload;
use App\Client\Http\MultipartFormDataBody;
use App\Client\Icon\IconsSnapshot;
use App\Client\Icon\IconUpload;
use App\Client\Indicator\IndicatorPayload;
use App\Client\Notification\NotificationPayload;
use App\Client\Settings\BrightnessLevel;
use App\Client\Settings\SettingsPayload;
use App\Client\Settings\SettingsSnapshot;
use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepState;
use App\Client\Stats\StatsSnapshot;
use App\Client\Tracker\TrackerPayload;
use App\Client\Weather\WeatherPayload;
use App\Scenario\Validation\OutboundPayloadValidator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PixelcastClient implements PixelcastClientInterface
{
    private const string WEATHER_SPEC_PATH = '/weather';
    private const string TRACKER_SPEC_PATH = '/tracker';
    private const string GAUGE_SPEC_PATH = '/gauge';
    private const string CUSTOM_APP_SPEC_PATH = '/custom';
    private const string NOTIFICATION_SPEC_PATH = '/notify';
    private const string NOTIFICATION_DISMISS_SPEC_PATH = '/notify/dismiss';
    private const string SLEEP_SPEC_PATH = '/sleep';
    private const string SETTINGS_SPEC_PATH = '/settings';
    private const string BRIGHTNESS_SPEC_PATH = '/brightness';
    private const string STATS_SPEC_PATH = '/stats';
    private const string ICONS_SPEC_PATH = '/icons';
    private const string LAMETRIC_ICON_SPEC_PATH = '/icons/lametric';
    private const string UPLOADED_ICON_FIELD_NAME = 'file';
    private const string INDICATOR_SPEC_PATH_PREFIX = '/indicator';
    private const int MINIMUM_INDICATOR_SLOT = 1;
    private const int MAXIMUM_INDICATOR_SLOT = 3;
    private const string REBOOT_SPEC_PATH = '/reboot';

    public function __construct(
        #[Target('device.client')]
        private HttpClientInterface $deviceClient,
        private OutboundPayloadValidator $outboundPayloadValidator,
    ) {
    }

    public function pushWeather(WeatherPayload $weather): void
    {
        $this->sendValidated('POST', self::WEATHER_SPEC_PATH, body: $weather->toArray());
    }

    public function pushTracker(TrackerPayload $tracker): void
    {
        $this->sendValidated('POST', self::TRACKER_SPEC_PATH, queryParameters: ['name' => $tracker->name], body: $tracker->toArray());
    }

    public function deleteTracker(string $trackerName): void
    {
        $this->sendValidated('DELETE', self::TRACKER_SPEC_PATH, queryParameters: ['name' => $trackerName]);
    }

    public function pushGauge(GaugePayload $gauge): void
    {
        $this->sendValidated('POST', self::GAUGE_SPEC_PATH, queryParameters: ['name' => $gauge->name], body: $gauge->toArray());
    }

    public function pushCustomApp(CustomAppPayload $customApp): void
    {
        $this->sendValidated('POST', self::CUSTOM_APP_SPEC_PATH, queryParameters: ['name' => $customApp->name], body: $customApp->toArray());
    }

    public function deleteCustomApp(string $customAppName): void
    {
        $this->sendValidated('DELETE', self::CUSTOM_APP_SPEC_PATH, queryParameters: ['name' => $customAppName]);
    }

    public function pushNotification(NotificationPayload $notification): void
    {
        $this->sendValidated('POST', self::NOTIFICATION_SPEC_PATH, body: $notification->toArray());
    }

    public function dismissNotification(): void
    {
        $this->sendValidated('POST', self::NOTIFICATION_DISMISS_SPEC_PATH);
    }

    public function pushSleepConfiguration(SleepPayload $sleep): void
    {
        $this->sendValidated('POST', self::SLEEP_SPEC_PATH, body: $sleep->toArray());
    }

    public function fetchSleepState(): SleepState
    {
        return SleepState::fromResponseBody($this->fetchDecodedBody(self::SLEEP_SPEC_PATH));
    }

    public function fetchSettings(): SettingsSnapshot
    {
        return SettingsSnapshot::fromResponseBody($this->fetchDecodedBody(self::SETTINGS_SPEC_PATH));
    }

    public function pushSettings(SettingsPayload $settings): void
    {
        $this->sendValidated('POST', self::SETTINGS_SPEC_PATH, body: $settings->toArray());
    }

    public function pushBrightness(BrightnessLevel $brightness): void
    {
        $this->sendValidated('POST', self::BRIGHTNESS_SPEC_PATH, body: ['brightness' => $brightness->level]);
    }

    public function fetchStats(): StatsSnapshot
    {
        return StatsSnapshot::fromResponseBody($this->fetchDecodedBody(self::STATS_SPEC_PATH));
    }

    public function listIcons(): IconsSnapshot
    {
        return IconsSnapshot::fromResponseBody($this->fetchDecodedBody(self::ICONS_SPEC_PATH));
    }

    public function uploadIcon(IconUpload $icon): void
    {
        $multipartBody = MultipartFormDataBody::forFile(
            self::UPLOADED_ICON_FIELD_NAME,
            $icon->fileName(),
            $icon->mimeType,
            $icon->binaryContents,
        );

        // The outbound validator only builds JSON requests, so this one is checked by IconUpload instead.
        $this->performRequest('POST', self::ICONS_SPEC_PATH, [
            'query' => ['name' => $icon->name],
            'headers' => ['Content-Type' => $multipartBody->contentTypeHeader()],
            'body' => $multipartBody->contents,
        ]);
    }

    public function deleteIcon(string $iconName): void
    {
        $this->sendValidated('DELETE', self::ICONS_SPEC_PATH, queryParameters: ['name' => $iconName]);
    }

    public function downloadLaMetricIcon(int $laMetricIconId, ?string $iconName = null): void
    {
        $body = ['id' => $laMetricIconId];

        if (null !== $iconName) {
            $body['name'] = $iconName;
        }

        $this->sendValidated('POST', self::LAMETRIC_ICON_SPEC_PATH, body: $body);
    }

    public function setIndicator(int $slot, IndicatorPayload $indicator): void
    {
        $this->sendValidated('POST', self::indicatorSpecPath($slot), body: $indicator->toArray());
    }

    public function clearIndicator(int $slot): void
    {
        $this->sendValidated('DELETE', self::indicatorSpecPath($slot));
    }

    public function reboot(): void
    {
        $this->sendValidated('POST', self::REBOOT_SPEC_PATH);
    }

    private static function indicatorSpecPath(int $slot): string
    {
        if ($slot < self::MINIMUM_INDICATOR_SLOT || $slot > self::MAXIMUM_INDICATOR_SLOT) {
            throw new \InvalidArgumentException(\sprintf('An indicator slot must be between %d and %d, got %d.', self::MINIMUM_INDICATOR_SLOT, self::MAXIMUM_INDICATOR_SLOT, $slot));
        }

        return self::INDICATOR_SPEC_PATH_PREFIX.$slot;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDecodedBody(string $specPath): array
    {
        $responseBody = $this->requestValidated('GET', $specPath);
        $decodedBody = json_decode($responseBody, true);

        if (!\is_array($decodedBody)) {
            throw InvalidPayloadException::fromUnreadableDeviceResponse($specPath, $responseBody);
        }

        /** @var array<string, mixed> $decodedBody */
        return $decodedBody;
    }

    /**
     * @param array<string, string> $queryParameters
     * @param array<string, mixed>|null $body
     */
    private function sendValidated(string $httpMethod, string $specPath, array $queryParameters = [], ?array $body = null): void
    {
        $this->requestValidated($httpMethod, $specPath, $queryParameters, $body);
    }

    /**
     * @param array<string, string> $queryParameters
     * @param array<string, mixed>|null $body
     */
    private function requestValidated(string $httpMethod, string $specPath, array $queryParameters = [], ?array $body = null): string
    {
        $validation = $this->outboundPayloadValidator->validateRequest($httpMethod, $specPath, $queryParameters, $body);

        if (!$validation->valid) {
            throw InvalidPayloadException::fromLocalValidation($specPath, $validation->errorMessage ?? 'invalid payload');
        }

        $requestOptions = [];

        if ([] !== $queryParameters) {
            $requestOptions['query'] = $queryParameters;
        }

        if (null !== $body) {
            $requestOptions['json'] = $body;
        }

        return $this->performRequest($httpMethod, $specPath, $requestOptions);
    }

    /**
     * @param array<string, mixed> $requestOptions
     */
    private function performRequest(string $httpMethod, string $specPath, array $requestOptions): string
    {
        try {
            // The spec path is absolute, the scoped client needs it relative to resolve it against a base URI already carrying /api.
            $response = $this->deviceClient->request($httpMethod, ltrim($specPath, '/'), $requestOptions);
            $httpStatus = $response->getStatusCode();
            $responseBody = $response->getContent(false);
        } catch (TransportExceptionInterface $transportFailure) {
            throw DeviceUnreachableException::forPath($specPath, $transportFailure);
        }

        $this->assertAccepted($specPath, $httpStatus, $responseBody);

        return $responseBody;
    }

    private function assertAccepted(string $specPath, int $httpStatus, string $responseBody): void
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return;
        }

        throw match ($httpStatus) {
            400 => InvalidPayloadException::fromDeviceResponse($specPath, self::deviceErrorMessage($responseBody)),
            404 => ResourceNotFoundException::forPath($specPath),
            500 => DeviceBusyException::slotExhausted($specPath),
            503 => DeviceBusyException::queueFull($specPath),
            default => DeviceUnreachableException::unexpectedStatus($specPath, $httpStatus, $responseBody),
        };
    }

    private static function deviceErrorMessage(string $responseBody): string
    {
        $decodedBody = json_decode($responseBody, true);
        $deviceMessage = \is_array($decodedBody) ? $decodedBody['error'] ?? null : null;

        return \is_string($deviceMessage) ? $deviceMessage : $responseBody;
    }
}
