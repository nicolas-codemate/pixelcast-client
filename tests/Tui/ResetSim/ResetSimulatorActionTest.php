<?php

declare(strict_types=1);

namespace App\Tests\Tui\ResetSim;

use App\Tests\Tui\Scenarios\Stub\CapturingScenarioTransportStub;
use App\Tests\Tui\Scenarios\Stub\ThrowingScenarioTransportStub;
use App\Tui\ResetSim\ResetSimulatorAction;
use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDispatcher;
use App\Tui\Scenarios\ScenarioResult;
use App\Tui\Scenarios\ScenarioResultKind;
use App\Tui\Scenarios\Validation\OutboundOpenApiValidatorFactory;
use App\Tui\Scenarios\Validation\OutboundPayloadValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ResetSimulatorActionTest extends TestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://simulator:8080';

    private OutboundPayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $projectDir = \dirname(__DIR__, 3);
        $factory = new OutboundOpenApiValidatorFactory($projectDir, self::TEST_DEVICE_BASE_URL);
        $this->validator = new OutboundPayloadValidator(
            $factory->create(),
            new Psr17Factory(),
            self::TEST_DEVICE_BASE_URL,
        );
    }

    public function testResetDispatchesResetSimulatorScenarioAndReturnsTransportResult(): void
    {
        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, self::TEST_DEVICE_BASE_URL);
        $action = new ResetSimulatorAction(new ScenarioCatalog(), $dispatcher);

        $result = $action->reset();

        self::assertSame(ScenarioResultKind::Success, $result->kind);
        self::assertSame(200, $result->httpStatus);
        self::assertCount(1, $transport->calls);
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertStringEndsWith('/__reset', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
    }

    public function testResetReturnsTransportFailureWhenDispatcherTransportThrows(): void
    {
        $throwingTransport = new ThrowingScenarioTransportStub(new \RuntimeException('boom'));
        $dispatcher = new ScenarioDispatcher($this->validator, $throwingTransport, self::TEST_DEVICE_BASE_URL);
        $action = new ResetSimulatorAction(new ScenarioCatalog(), $dispatcher);

        $result = $action->reset();

        self::assertSame(ScenarioResultKind::TransportFailure, $result->kind);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('boom', $result->message);
    }
}
