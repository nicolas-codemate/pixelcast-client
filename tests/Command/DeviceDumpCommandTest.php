<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Client\Http\HttpJsonFetcher;
use App\Client\Inspector\InspectorSnapshot;
use App\Client\Inspector\InspectorTransport;
use App\Client\Reachability\DeviceReachabilityProbe;
use App\Client\State\DeviceStateSourceFactory;
use App\Client\Transport\IconsTransport;
use App\Client\Transport\NotificationsTransport;
use App\Client\Transport\SettingsTransport;
use App\Client\Transport\TrackersTransport;
use App\Client\Transport\WeatherTransport;
use App\Command\DeviceDumpCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeviceDumpCommandTest extends TestCase
{
    private const string UNREACHABLE_BASE_URL = 'http://127.0.0.1:1/api';

    /** @var resource|null */
    private $localListener;

    private string $localBaseUrl;

    /** @var array<string, array<string, mixed>> */
    private array $restResponsesByUrl = [];

    /** @var list<string> */
    private array $requestedRestUrls = [];

    protected function setUp(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $listener) {
            self::markTestSkipped('no local TCP port could be opened for the reachability probe');
        }

        $listenerAddress = stream_socket_get_name($listener, false);
        if (false === $listenerAddress) {
            self::markTestSkipped('the local TCP listener has no readable address');
        }

        $this->localListener = $listener;
        $this->localBaseUrl = 'http://'.$listenerAddress.'/api';
    }

    protected function tearDown(): void
    {
        if (null !== $this->localListener) {
            fclose($this->localListener);
            $this->localListener = null;
        }
    }

    public function testASimulatorTargetIsReadFromTheInspectorEndpoint(): void
    {
        $tester = $this->createTester(
            InspectorSnapshot::fromInspectPayload(['state' => ['weather' => ['current' => ['temp' => 18]]]]),
            $this->localBaseUrl,
        );

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"temp": 18', $tester->getDisplay());
        self::assertSame([], $this->requestedRestUrls);
    }

    public function testAFirmwareTargetIsReadFromTheRestApi(): void
    {
        $this->restResponsesByUrl[$this->localBaseUrl.'/weather'] = ['current' => ['temp' => 21]];

        $tester = $this->createTester(
            InspectorSnapshot::unreachable('invalid response'),
            self::UNREACHABLE_BASE_URL,
        );

        $exitCode = $tester->execute(['--target' => $this->localBaseUrl]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"temp": 21', $tester->getDisplay());
        self::assertContains($this->localBaseUrl.'/weather', $this->requestedRestUrls);
    }

    public function testATargetThatMatchesNeitherShapeIsExplained(): void
    {
        $tester = $this->createTester(
            InspectorSnapshot::unreachable('invalid response'),
            $this->localBaseUrl,
        );

        $exitCode = $tester->execute([]);

        $display = $this->singleLineDisplay($tester);
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('not as a PixelCast device.', $display);
        self::assertStringContainsString('/__inspect: invalid response', $display);
        self::assertStringNotContainsString('"weather": null', $display);
    }

    public function testAnUnreachableTargetFails(): void
    {
        $tester = $this->createTester(
            InspectorSnapshot::unreachable('connection failed'),
            $this->localBaseUrl,
        );

        $exitCode = $tester->execute(['--target' => self::UNREACHABLE_BASE_URL]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('unreachable', $this->singleLineDisplay($tester));
    }

    public function testAnUnknownDomainIsRejected(): void
    {
        $tester = $this->createTester(
            InspectorSnapshot::fromInspectPayload(['state' => ['weather' => ['current' => ['temp' => 18]]]]),
            $this->localBaseUrl,
        );

        $exitCode = $tester->execute(['--domain' => 'nope']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unknown domain "nope"', $this->singleLineDisplay($tester));
    }

    public function testTheDumpCanBeRestrictedToASingleDomain(): void
    {
        $tester = $this->createTester(
            InspectorSnapshot::fromInspectPayload(['state' => [
                'weather' => ['current' => ['temp' => 18]],
                'icons' => ['icons' => [['name' => 'sun']]],
            ]]),
            $this->localBaseUrl,
        );

        $exitCode = $tester->execute(['--domain' => 'weather']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $dumpedPayloads = json_decode($tester->getDisplay(), true);
        self::assertIsArray($dumpedPayloads);
        self::assertSame(['weather'], array_keys($dumpedPayloads));
    }

    public function testTheDetectedTargetKindIsAnnouncedInVerboseMode(): void
    {
        $this->restResponsesByUrl[$this->localBaseUrl.'/weather'] = ['current' => ['temp' => 21]];

        $tester = $this->createTester(
            InspectorSnapshot::unreachable('invalid response'),
            $this->localBaseUrl,
        );

        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringContainsString(
            \sprintf('Reading %s as a firmware target.', $this->localBaseUrl),
            $this->singleLineDisplay($tester),
        );
    }

    private function createTester(InspectorSnapshot $inspectorSnapshot, ?string $deviceBaseUrl): CommandTester
    {
        $restFetcher = new HttpJsonFetcher($this->createRestHttpClient(), new NullLogger());

        $deviceStateSourceFactory = new DeviceStateSourceFactory(
            new CannedInspectorTransportStub($inspectorSnapshot),
            new WeatherTransport($restFetcher),
            new TrackersTransport($restFetcher),
            new NotificationsTransport($restFetcher),
            new IconsTransport($restFetcher),
            new SettingsTransport($restFetcher),
        );

        return new CommandTester(new DeviceDumpCommand(
            $deviceBaseUrl,
            new DeviceReachabilityProbe(),
            $deviceStateSourceFactory,
        ));
    }

    private function createRestHttpClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedRestUrls[] = $url;

            $payload = $this->restResponsesByUrl[$url] ?? null;
            if (null === $payload) {
                return new MockResponse('Not Found', ['http_code' => 404]);
            }

            return new MockResponse((string) json_encode($payload));
        });
    }

    /**
     * Console blocks wrap on the terminal width, so assertions run on a whitespace-collapsed display.
     */
    private function singleLineDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}

final class CannedInspectorTransportStub implements InspectorTransport
{
    public function __construct(private readonly InspectorSnapshot $cannedSnapshot)
    {
    }

    public function fetch(?string $baseUrl): InspectorSnapshot
    {
        return $this->cannedSnapshot;
    }
}
