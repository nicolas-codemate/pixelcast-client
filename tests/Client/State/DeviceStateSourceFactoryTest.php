<?php

declare(strict_types=1);

namespace App\Tests\Client\State;

use App\Client\Http\HttpJsonFetcher;
use App\Client\Inspector\InspectorSnapshot;
use App\Client\Inspector\InspectorTransport;
use App\Client\State\DevDeviceStateSource;
use App\Client\State\DeviceStateSourceFactory;
use App\Client\State\DeviceTargetKind;
use App\Client\State\ProdDeviceStateSource;
use App\Client\Transport\IconsTransport;
use App\Client\Transport\NotificationsTransport;
use App\Client\Transport\SettingsTransport;
use App\Client\Transport\TrackersTransport;
use App\Client\Transport\WeatherTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class DeviceStateSourceFactoryTest extends TestCase
{
    private const string BASE_URL = 'http://device.test/api';

    public function testATargetAnsweringTheInspectorEndpointIsReadAsASimulator(): void
    {
        $inspectorTransport = new RecordingInspectorTransportStub();
        $inspectorTransport->cannedSnapshot = InspectorSnapshot::fromInspectPayload([
            'state' => ['weather' => ['current' => ['temp' => 18]]],
        ]);

        $selection = $this->buildFactory($inspectorTransport)->createForTarget(self::BASE_URL);

        self::assertSame(DeviceTargetKind::Simulator, $selection->targetKind);
        self::assertInstanceOf(DevDeviceStateSource::class, $selection->stateSource);
        self::assertNull($selection->inspectorProbeError);
    }

    public function testTheProbeResponseIsReusedByTheSimulatorSource(): void
    {
        $inspectorTransport = new RecordingInspectorTransportStub();
        $inspectorTransport->cannedSnapshot = InspectorSnapshot::fromInspectPayload([
            'state' => ['weather' => ['current' => ['temp' => 18]]],
        ]);

        $selection = $this->buildFactory($inspectorTransport)->createForTarget(self::BASE_URL);
        $selection->stateSource->snapshot();

        self::assertSame(1, $inspectorTransport->fetchCount);
    }

    public function testATargetWithoutInspectorEndpointIsReadAsAFirmware(): void
    {
        $inspectorTransport = new RecordingInspectorTransportStub();
        $inspectorTransport->cannedSnapshot = InspectorSnapshot::unreachable('invalid response');

        $selection = $this->buildFactory($inspectorTransport)->createForTarget(self::BASE_URL);

        self::assertSame(DeviceTargetKind::Firmware, $selection->targetKind);
        self::assertInstanceOf(ProdDeviceStateSource::class, $selection->stateSource);
        self::assertSame('invalid response', $selection->inspectorProbeError);
    }

    public function testAnInspectorResponseWithoutStateIsReadAsAFirmware(): void
    {
        $inspectorTransport = new RecordingInspectorTransportStub();
        $inspectorTransport->cannedSnapshot = InspectorSnapshot::fromInspectPayload([]);

        $selection = $this->buildFactory($inspectorTransport)->createForTarget(self::BASE_URL);

        self::assertSame(DeviceTargetKind::Firmware, $selection->targetKind);
        self::assertInstanceOf(ProdDeviceStateSource::class, $selection->stateSource);
        self::assertNotNull($selection->inspectorProbeError);
    }

    private function buildFactory(InspectorTransport $inspectorTransport): DeviceStateSourceFactory
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

final class RecordingInspectorTransportStub implements InspectorTransport
{
    public int $fetchCount = 0;
    public ?InspectorSnapshot $cannedSnapshot = null;

    public function fetch(?string $baseUrl): InspectorSnapshot
    {
        ++$this->fetchCount;

        return $this->cannedSnapshot ?? InspectorSnapshot::unreachable('connection failed');
    }
}
