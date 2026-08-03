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
     * Only the simulator answers /__inspect, so probing it tells which kind of target is on the line.
     * The probe response is handed over to the simulator source, which reuses it instead of asking again.
     */
    public function createForTarget(?string $baseUrl): DeviceTargetSelection
    {
        $inspectorSnapshot = $this->inspectorTransport->fetch($baseUrl);

        if ($inspectorSnapshot->reachable && null !== $inspectorSnapshot->state) {
            return DeviceTargetSelection::simulator(
                new DevDeviceStateSource($this->inspectorTransport, $baseUrl, $inspectorSnapshot),
            );
        }

        return DeviceTargetSelection::firmware(
            $this->createForFirmware($baseUrl),
            $inspectorSnapshot->errorMessage ?? 'no state in the /__inspect response',
        );
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
