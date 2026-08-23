<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        yield 'single tracker' => ['/api/tracker?name=BTC'];
        yield 'gauge list' => ['/api/gauges'];
        yield 'single gauge' => ['/api/gauge?name=disks'];
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
        // AppListResponse declares `apps` as an array of objects, so a string there is a
        // mismatch the response pass has to catch.
        $this->replaceControllerWith(static fn (): JsonResponse => new JsonResponse([
            'apps' => 'not-an-array',
            'count' => 0,
        ]));

        $this->client->request('GET', '/api/apps');

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('error', $this->jsonResponse());
    }

    public function testControllerFailureKeepsItsOwnErrorResponse(): void
    {
        $thrownMessage = 'the simulator controller blew up';
        $this->replaceControllerWith(static fn (): never => throw new \RuntimeException($thrownMessage));

        $this->client->request('GET', '/api/trackers');

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        // A validated response would be the validator's own application/json complaint instead.
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString($thrownMessage, (string) $response->getContent());
    }

    /**
     * Swaps the matched controller once the request pass has run, which is the only way to
     * reach the response pass with something the spec forbids.
     */
    private function replaceControllerWith(callable $controller): void
    {
        $eventDispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);
        $eventDispatcher->addListener(
            KernelEvents::CONTROLLER,
            static function (ControllerEvent $event) use ($controller): void {
                if ($event->isMainRequest()) {
                    $event->setController($controller);
                }
            },
            -100,
        );
    }

    private function pushOnePayloadOfEachKind(): void
    {
        $this->postJson('/api/tracker?name=BTC', self::trackerPushPayload());

        $this->postJson('/api/gauge?name=disks', [
            'title' => 'Disks',
            'rows' => [['label' => 'root', 'percent' => 42, 'color' => [255, 136, 0]]],
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
