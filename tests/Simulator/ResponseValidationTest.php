<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ResponseValidationTest extends SimulatorWebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function specifiedReadEndpointProvider(): iterable
    {
        yield 'tracker list' => ['/api/trackers'];
        yield 'gauge list' => ['/api/gauges'];
        yield 'custom app list' => ['/api/apps'];
        yield 'notification list' => ['/api/notify/list'];
        yield 'icon list' => ['/api/icons'];
        yield 'sleep' => ['/api/sleep'];
        yield 'stats' => ['/api/stats'];
        yield 'settings' => ['/api/settings'];
    }

    #[DataProvider('specifiedReadEndpointProvider')]
    public function testReadEndpointMatchesItsResponseSchemaAfterAPush(string $path): void
    {
        $this->pushOnePayloadOfEachKind();

        $this->client->request('GET', $path);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testGetWeatherOnTheEmptyStateMatchesItsResponseSchema(): void
    {
        $this->client->request('GET', '/api/weather');

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testResponseTheSpecForbidsIsAnsweredWithAnError(): void
    {
        // POST /api/custom accepts an RGB array as color while AppResponse only allows a hex
        // string, so echoing that colour back is a mismatch a legitimate request can produce.
        $this->postJson('/api/custom?name=rgb', ['text' => 'hi', 'color' => [255, 136, 0]]);
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/apps');

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('error', $this->jsonResponse());
    }

    public function testControllerFailureKeepsItsOwnErrorResponse(): void
    {
        $thrownMessage = 'the simulator controller blew up';
        $eventDispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);
        // Replaces the matched controller once the request pass has run, which is the only way
        // to reach the response pass through Symfony's exception handling.
        $eventDispatcher->addListener(
            KernelEvents::CONTROLLER,
            static function (ControllerEvent $event) use ($thrownMessage): void {
                if ($event->isMainRequest()) {
                    $event->setController(static fn (): never => throw new \RuntimeException($thrownMessage));
                }
            },
            -100,
        );

        $this->client->request('GET', '/api/trackers');

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        // A validated response would be the validator's own application/json complaint instead.
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString($thrownMessage, (string) $response->getContent());
    }

    private function pushOnePayloadOfEachKind(): void
    {
        $this->postJson('/api/tracker?name=BTC', self::trackerPushPayload());

        $this->postJson('/api/gauge?name=disks', [
            'title' => 'Disks',
            'rows' => [['label' => 'root', 'percent' => 42]],
        ]);

        $this->postJson('/api/custom?name=foo', self::customAppPushPayload());

        $this->postJson('/api/notify', [
            'text' => 'New message!',
            'icon' => 'mail',
            'color' => '#0096FF',
            'duration' => 5_000,
        ]);
    }
}
