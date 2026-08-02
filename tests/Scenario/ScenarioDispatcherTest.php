<?php

declare(strict_types=1);

namespace App\Tests\Scenario;

use App\Scenario\ScenarioCatalog;
use App\Scenario\ScenarioDefinition;
use App\Scenario\ScenarioDispatcher;
use App\Scenario\ScenarioResult;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use App\Tests\Scenario\Stub\CapturingScenarioTransportStub;
use App\Tests\Scenario\Stub\ThrowingScenarioTransportStub;
use App\Tests\Stub\RecordingLoggerStub;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

final class ScenarioDispatcherTest extends TestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://simulator:8080/api';

    private OutboundPayloadValidator $validator;
    private ScenarioCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $projectDir = \dirname(__DIR__, 2);

        $factory = new OutboundOpenApiValidatorFactory($projectDir, self::TEST_DEVICE_BASE_URL);
        $this->validator = new OutboundPayloadValidator(
            $factory->create(),
            new Psr17Factory(),
            self::TEST_DEVICE_BASE_URL,
        );
        $this->catalog = new ScenarioCatalog();
    }

    public function testValidationFailureShortCircuitsAndDoesNotCallTransport(): void
    {
        $invalidNotification = new ScenarioDefinition(
            id: 'notification-invalid',
            label: 'Notification - missing text',
            description: 'invalid: required text field stripped',
            httpMethod: 'POST',
            path: '/notify',
            body: ['icon' => 'mail'],
        );

        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $logger = new RecordingLoggerStub();
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, $logger, self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($invalidNotification);

        self::assertFalse($result->success);
        self::assertNull($result->httpStatus);
        self::assertSame([], $transport->calls);

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('validation_failure', $logger->records[0]['context']['kind']);
    }

    public function testResetScenarioBypassesValidationAndCallsTransportOnce(): void
    {
        $reset = $this->catalog->findById('reset-simulator', true);
        self::assertNotNull($reset);

        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, new NullLogger(), self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($reset);

        self::assertTrue($result->success);
        self::assertSame(200, $result->httpStatus);
        self::assertCount(1, $transport->calls);
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertStringEndsWith('/__reset', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
    }

    public function testWeatherScenarioValidatesAndPostsPayloadToWeatherEndpoint(): void
    {
        $weather = $this->catalog->findById('weather', true);
        self::assertNotNull($weather);

        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, new NullLogger(), self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($weather);

        self::assertTrue($result->success);
        self::assertSame(200, $result->httpStatus);
        self::assertCount(1, $transport->calls);
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertStringEndsWith('/weather', $transport->calls[0]['url']);
        self::assertSame($weather->body, $transport->calls[0]['body']);
    }

    public function testTrackerScenarioBuildsUrlWithQueryString(): void
    {
        $tracker = $this->catalog->findById('tracker-btc', true);
        self::assertNotNull($tracker);

        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, new NullLogger(), self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($tracker);

        self::assertTrue($result->success);
        self::assertCount(1, $transport->calls);
        self::assertStringContainsString('/tracker?name=BTC', $transport->calls[0]['url']);
    }

    public function testTransportExceptionIsConvertedToTransportFailure(): void
    {
        $weather = $this->catalog->findById('weather', true);
        self::assertNotNull($weather);

        $throwingTransport = new ThrowingScenarioTransportStub(new \RuntimeException('boom'));
        $logger = new RecordingLoggerStub();
        $dispatcher = new ScenarioDispatcher($this->validator, $throwingTransport, $logger, self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($weather);

        self::assertFalse($result->success);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('boom', $result->message);

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('Scenario dispatch failed', $logger->records[0]['message']);
        self::assertSame('weather', $logger->records[0]['context']['scenario']);
        self::assertSame('Transport error: boom', $logger->records[0]['context']['error']);
    }
}
