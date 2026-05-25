<?php

declare(strict_types=1);

namespace App\Tests\Tui\Scenarios;

use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDefinition;
use App\Tui\Scenarios\ScenarioDispatcher;
use App\Tui\Scenarios\ScenarioResult;
use App\Tui\Scenarios\Transport\ScenarioTransport;
use App\Tui\Scenarios\Validation\OutboundOpenApiValidatorFactory;
use App\Tui\Scenarios\Validation\OutboundPayloadValidator;
use App\Tui\TuiMode;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ScenarioDispatcherTest extends TestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://simulator:8080';

    private OutboundPayloadValidator $validator;
    private ScenarioCatalog $catalog;

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
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($invalidNotification);

        self::assertFalse($result->success);
        self::assertNull($result->httpStatus);
        self::assertSame([], $transport->calls);
    }

    public function testResetScenarioBypassesValidationAndCallsTransportOnce(): void
    {
        $reset = $this->catalog->findById('reset-simulator', TuiMode::Dev);
        self::assertNotNull($reset);

        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, self::TEST_DEVICE_BASE_URL);

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
        $weather = $this->catalog->findById('weather', TuiMode::Dev);
        self::assertNotNull($weather);

        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, self::TEST_DEVICE_BASE_URL);

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
        $tracker = $this->catalog->findById('tracker-btc', TuiMode::Dev);
        self::assertNotNull($tracker);

        $transport = new CapturingScenarioTransportStub(ScenarioResult::success(200, 'OK 200'));
        $dispatcher = new ScenarioDispatcher($this->validator, $transport, self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($tracker);

        self::assertTrue($result->success);
        self::assertCount(1, $transport->calls);
        self::assertStringContainsString('/tracker?name=BTC', $transport->calls[0]['url']);
    }

    public function testTransportExceptionIsConvertedToTransportFailure(): void
    {
        $weather = $this->catalog->findById('weather', TuiMode::Dev);
        self::assertNotNull($weather);

        $throwingTransport = new ThrowingScenarioTransportStub(new \RuntimeException('boom'));
        $dispatcher = new ScenarioDispatcher($this->validator, $throwingTransport, self::TEST_DEVICE_BASE_URL);

        $result = $dispatcher->dispatch($weather);

        self::assertFalse($result->success);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('boom', $result->message);
    }
}

final class CapturingScenarioTransportStub implements ScenarioTransport
{
    /**
     * @var list<array{method: string, url: string, body: array<string,mixed>|null}>
     */
    public array $calls = [];

    public function __construct(
        private readonly ScenarioResult $resultToReturn,
    ) {
    }

    public function send(string $method, string $url, ?array $body): ScenarioResult
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
        ];

        return $this->resultToReturn;
    }
}

final class ThrowingScenarioTransportStub implements ScenarioTransport
{
    public function __construct(
        private readonly \Throwable $exceptionToThrow,
    ) {
    }

    public function send(string $method, string $url, ?array $body): ScenarioResult
    {
        throw $this->exceptionToThrow;
    }
}
