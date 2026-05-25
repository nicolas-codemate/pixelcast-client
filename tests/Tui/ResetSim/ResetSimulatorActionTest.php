<?php

declare(strict_types=1);

namespace App\Tests\Tui\ResetSim;

use App\Tests\Tui\Scenarios\Stub\CapturingScenarioTransportStub;
use App\Tests\Tui\Scenarios\Stub\ThrowingScenarioTransportStub;
use App\Tui\ResetSim\ResetSimulatorAction;
use App\Tui\Scenarios\DeviceBaseUrl;
use App\Tui\Scenarios\ScenarioResult;
use App\Tui\Scenarios\ScenarioResultKind;
use PHPUnit\Framework\TestCase;

final class ResetSimulatorActionTest extends TestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://simulator:8080';

    public function testResetSendsPostToResetEndpointWithoutBody(): void
    {
        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200));
        $action = new ResetSimulatorAction($transport, self::TEST_DEVICE_BASE_URL);

        $result = $action->reset();

        self::assertSame(ScenarioResultKind::Success, $result->kind);
        self::assertSame(200, $result->httpStatus);
        self::assertCount(1, $transport->calls);
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertSame(self::TEST_DEVICE_BASE_URL.'/__reset', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
    }

    public function testResetReturnsTransportFailureWhenTransportThrows(): void
    {
        $throwingTransport = new ThrowingScenarioTransportStub(new \RuntimeException('boom'));
        $action = new ResetSimulatorAction($throwingTransport, self::TEST_DEVICE_BASE_URL);

        $result = $action->reset();

        self::assertSame(ScenarioResultKind::TransportFailure, $result->kind);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('boom', $result->message);
    }

    public function testResetResolvesBaseUrlFromDefaultWhenConfigIsNull(): void
    {
        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200));
        $action = new ResetSimulatorAction($transport, null);

        $action->reset();

        self::assertCount(1, $transport->calls);
        self::assertStringStartsWith(DeviceBaseUrl::DEFAULT, $transport->calls[0]['url']);
        self::assertStringEndsWith('/__reset', $transport->calls[0]['url']);
    }
}
