<?php

declare(strict_types=1);

namespace App\Client\State;

use App\Client\Inspector\InspectorTransport;
use App\Client\Transport\IconsTransport;
use App\Client\Transport\NotificationsTransport;
use App\Client\Transport\SettingsTransport;
use App\Client\Transport\TrackersTransport;
use App\Client\Transport\WeatherTransport;

final readonly class DeviceStateSourceFactory
{
    public function __construct(
        private InspectorTransport $inspectorTransport,
        private WeatherTransport $weatherTransport,
        private TrackersTransport $trackersTransport,
        private NotificationsTransport $notificationsTransport,
        private IconsTransport $iconsTransport,
        private SettingsTransport $settingsTransport,
    ) {
    }

    /**
     * The simulator returns every domain in one /__inspect response.
     */
    public function createForSimulator(?string $baseUrl): DeviceStateSource
    {
        return new DevDeviceStateSource($this->inspectorTransport, $baseUrl);
    }

    /**
     * The firmware has no /__inspect, so each domain needs its own GET.
     */
    public function createForFirmware(?string $baseUrl): DeviceStateSource
    {
        return new ProdDeviceStateSource(
            $this->weatherTransport,
            $this->trackersTransport,
            $this->notificationsTransport,
            $this->iconsTransport,
            $this->settingsTransport,
            $baseUrl,
        );
    }
}
