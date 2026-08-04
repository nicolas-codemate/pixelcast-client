<?php

declare(strict_types=1);

namespace App\Client;

use App\Client\Exception\DeviceBusyException;
use App\Client\Exception\DeviceUnreachableException;
use App\Client\Exception\InvalidPayloadException;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Notification\NotificationPayload;
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
    private const string NOTIFICATION_SPEC_PATH = '/notify';
    private const string NOTIFICATION_DISMISS_SPEC_PATH = '/notify/dismiss';

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
        $this->sendValidated('POST', self::TRACKER_SPEC_PATH, ['name' => $tracker->name], $tracker->toArray());
    }

    public function deleteTracker(string $trackerName): void
    {
        $this->sendValidated('DELETE', self::TRACKER_SPEC_PATH, ['name' => $trackerName]);
    }

    public function pushNotification(NotificationPayload $notification): void
    {
        $this->sendValidated('POST', self::NOTIFICATION_SPEC_PATH, body: $notification->toArray());
    }

    public function dismissNotification(): void
    {
        $this->sendValidated('POST', self::NOTIFICATION_DISMISS_SPEC_PATH);
    }

    /**
     * @param array<string, string> $queryParameters
     * @param array<string, mixed>|null $body
     */
    private function sendValidated(string $httpMethod, string $specPath, array $queryParameters = [], ?array $body = null): void
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

        try {
            // The spec path is absolute, the scoped client needs it relative to resolve it against a base URI already carrying /api.
            $response = $this->deviceClient->request($httpMethod, ltrim($specPath, '/'), $requestOptions);
            $httpStatus = $response->getStatusCode();
            $responseBody = $response->getContent(false);
        } catch (TransportExceptionInterface $transportFailure) {
            throw DeviceUnreachableException::forPath($specPath, $transportFailure);
        }

        $this->assertAccepted($specPath, $httpStatus, $responseBody);
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
