<?php

declare(strict_types=1);

namespace App\Client\State;

use App\Client\Transport\IconsTransport;
use App\Client\Transport\NotificationsTransport;
use App\Client\Transport\SettingsTransport;
use App\Client\Transport\TrackersTransport;
use App\Client\Transport\WeatherTransport;
use App\Domain\AppDomain;

final class ProdDeviceStateSource implements DeviceStateSource
{
    /** @var array<string, DeviceDomainState>|null keyed by AppDomain::value */
    private ?array $fetchedDomainStates = null;

    /** @var array<string, mixed>|null */
    private ?array $fetchedSettings = null;

    public function __construct(
        private readonly WeatherTransport $weatherTransport,
        private readonly TrackersTransport $trackersTransport,
        private readonly NotificationsTransport $notificationsTransport,
        private readonly IconsTransport $iconsTransport,
        private readonly SettingsTransport $settingsTransport,
        private readonly ?string $baseUrl,
    ) {
    }

    public function getDomainState(AppDomain $domain): DeviceDomainState
    {
        return $this->domainStates()[$domain->value];
    }

    public function snapshot(): array
    {
        return $this->domainStates();
    }

    public function reachabilityError(): ?string
    {
        foreach ($this->domainStates() as $domainState) {
            if (null !== $domainState->payload) {
                return null;
            }
        }

        // Firmware exposes no GET for indicator slots or custom apps, so /settings answering alone still proves the target is a device.
        if (null !== $this->fetchedSettings) {
            return null;
        }

        return 'no REST endpoint returned JSON';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestSettings(): ?array
    {
        $this->domainStates();

        return $this->fetchedSettings;
    }

    /**
     * @return array<string, DeviceDomainState>
     */
    private function domainStates(): array
    {
        return $this->fetchedDomainStates ??= $this->fetchAllDomains();
    }

    /**
     * @return array<string, DeviceDomainState>
     */
    private function fetchAllDomains(): array
    {
        $weatherPayload = $this->weatherTransport->fetch($this->baseUrl);
        $trackersPayload = $this->trackersTransport->fetch($this->baseUrl);
        $notificationsPayload = $this->notificationsTransport->fetch($this->baseUrl);
        $iconsPayload = $this->iconsTransport->fetch($this->baseUrl);
        $this->fetchedSettings = $this->settingsTransport->fetch($this->baseUrl);

        // Firmware exposes no GET for indicator slots or custom apps.
        $emptyDomainState = new DeviceDomainState(false, null);

        return [
            AppDomain::Weather->value => new DeviceDomainState(
                $this->hasWeatherData($weatherPayload),
                $weatherPayload,
            ),
            AppDomain::Trackers->value => new DeviceDomainState(
                $this->hasNonEmptyCollection($trackersPayload, 'trackers'),
                $trackersPayload,
            ),
            AppDomain::Notifications->value => new DeviceDomainState(
                $this->hasNonEmptyCollection($notificationsPayload, 'notifications'),
                $notificationsPayload,
            ),
            AppDomain::CustomApps->value => $emptyDomainState,
            AppDomain::Indicators->value => $emptyDomainState,
            AppDomain::Icons->value => new DeviceDomainState(
                $this->hasNonEmptyCollection($iconsPayload, 'icons'),
                $iconsPayload,
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function hasWeatherData(?array $payload): bool
    {
        return null !== $payload && null !== ($payload['current'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function hasNonEmptyCollection(?array $payload, string $collectionKey): bool
    {
        if (null === $payload) {
            return false;
        }
        $collection = $payload[$collectionKey] ?? null;

        return \is_array($collection) && [] !== $collection;
    }
}
