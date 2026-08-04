<?php

declare(strict_types=1);

namespace App\Tests\Client\State;

use App\Client\Http\HttpJsonFetcher;
use App\Client\Inspector\InspectorSnapshot;
use App\Client\State\DevDeviceStateSource;
use App\Client\State\DeviceStateSourceFactory;
use App\Client\State\DeviceTargetKind;
use App\Client\State\DeviceTargetSelection;
use App\Client\State\ProdDeviceStateSource;
use App\Client\Transport\IconsTransport;
use App\Client\Transport\NotificationsTransport;
use App\Client\Transport\SettingsTransport;
use App\Client\Transport\TrackersTransport;
use App\Client\Transport\WeatherTransport;
use App\Tests\Stub\RecordingInspectorTransportStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class DeviceStateSourceFactoryTest extends TestCase
{
    private const string BASE_URL = 'http://device.test/api';

    public function testATargetAnsweringTheInspectorEndpointIsReadAsASimulator(): void
    {
        $selection = $this->selectionForProbe($this->anInspectorAnswer());

        self::assertSame(DeviceTargetKind::Simulator, $selection->targetKind);
        self::assertInstanceOf(DevDeviceStateSource::class, $selection->stateSource);
        self::assertNull($selection->inspectorProbeError);
    }

    public function testTheProbeResponseIsReusedByTheSimulatorSource(): void
    {
        $inspectorTransport = new RecordingInspectorTransportStub($this->anInspectorAnswer());

        $selection = $this->buildFactory($inspectorTransport)->createForTarget(self::BASE_URL);
        $selection->stateSource->snapshot();

        self::assertSame(1, $inspectorTransport->fetchCount);
    }

    public function testATargetWithoutInspectorEndpointIsReadAsAFirmware(): void
    {
        $selection = $this->selectionForProbe(InspectorSnapshot::unreachable('invalid response'));

        self::assertSame(DeviceTargetKind::Firmware, $selection->targetKind);
        self::assertInstanceOf(ProdDeviceStateSource::class, $selection->stateSource);
        self::assertSame('invalid response', $selection->inspectorProbeError);
    }

    public function testAnInspectorResponseWithoutStateIsReadAsAFirmware(): void
    {
        $selection = $this->selectionForProbe(InspectorSnapshot::fromInspectPayload([]));

        self::assertSame(DeviceTargetKind::Firmware, $selection->targetKind);
        self::assertInstanceOf(ProdDeviceStateSource::class, $selection->stateSource);
        self::assertNotNull($selection->inspectorProbeError);
    }

    private function anInspectorAnswer(): InspectorSnapshot
    {
        return InspectorSnapshot::fromInspectPayload([
            'state' => ['weather' => ['current' => ['temp' => 18]]],
        ]);
    }

    private function selectionForProbe(InspectorSnapshot $probeSnapshot): DeviceTargetSelection
    {
        return $this->buildFactory(new RecordingInspectorTransportStub($probeSnapshot))
            ->createForTarget(self::BASE_URL);
    }

    private function buildFactory(RecordingInspectorTransportStub $inspectorTransport): DeviceStateSourceFactory
    {
        $fetcher = new HttpJsonFetcher(new MockHttpClient(), new NullLogger());

        return new DeviceStateSourceFactory(
            $inspectorTransport,
            new WeatherTransport($fetcher),
            new TrackersTransport($fetcher),
            new NotificationsTransport($fetcher),
            new IconsTransport($fetcher),
            new SettingsTransport($fetcher),
        );
    }
}
