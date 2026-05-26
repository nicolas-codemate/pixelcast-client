<?php

declare(strict_types=1);

namespace App\Tui\DeviceState\Prod;

use App\Domain\AppDomain;
use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\DeviceState\DeviceStateSource;
use App\Tui\DeviceState\Prod\Transport\IconsTransport;
use App\Tui\DeviceState\Prod\Transport\NotificationsTransport;
use App\Tui\DeviceState\Prod\Transport\SettingsTransport;
use App\Tui\DeviceState\Prod\Transport\TrackersTransport;
use App\Tui\DeviceState\Prod\Transport\WeatherTransport;

final class ProdDeviceStateSource implements DeviceStateSource
{
    /** @var array<string, DeviceDomainState> keyed by AppDomain::value */
    private array $latestDomainStates;

    /** @var array<string, mixed>|null */
    private ?array $latestSettings = null;

    private float $accumulatedSeconds = 0.0;

    public function __construct(
        private readonly WeatherTransport $weatherTransport,
        private readonly TrackersTransport $trackersTransport,
        private readonly NotificationsTransport $notificationsTransport,
        private readonly IconsTransport $iconsTransport,
        private readonly SettingsTransport $settingsTransport,
        private readonly ?string $baseUrl,
        private readonly float $pollIntervalSeconds = 2.0,
    ) {
        $this->latestDomainStates = [];
        foreach (AppDomain::cases() as $domain) {
            $this->latestDomainStates[$domain->value] = new DeviceDomainState(false, null);
        }
    }

    public function getDomainState(AppDomain $domain): DeviceDomainState
    {
        return $this->latestDomainStates[$domain->value];
    }

    public function snapshot(): array
    {
        return $this->latestDomainStates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestSettings(): ?array
    {
        return $this->latestSettings;
    }

    public function refresh(float $deltaSeconds): bool
    {
        $clampedDelta = max(0.0, $deltaSeconds);
        $this->accumulatedSeconds += $clampedDelta;
        if ($this->accumulatedSeconds < $this->pollIntervalSeconds) {
            return false;
        }
        $this->accumulatedSeconds = 0.0;
        $this->pollAllDomains();

        return true;
    }

    private function pollAllDomains(): void
    {
        $weatherPayload = $this->weatherTransport->fetch($this->baseUrl);
        $trackersPayload = $this->trackersTransport->fetch($this->baseUrl);
        $notificationsPayload = $this->notificationsTransport->fetch($this->baseUrl);
        $iconsPayload = $this->iconsTransport->fetch($this->baseUrl);
        $settingsPayload = $this->settingsTransport->fetch($this->baseUrl);

        $emptyDomainState = new DeviceDomainState(false, null);

        $this->latestDomainStates = [
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
        $this->latestSettings = $settingsPayload;
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
