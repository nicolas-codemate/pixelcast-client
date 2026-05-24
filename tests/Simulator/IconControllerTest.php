<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class IconControllerTest extends SimulatorWebTestCase
{
    public function testListInitiallyEmpty(): void
    {
        $this->client->request('GET', '/icons');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame(0, $payload['count'] ?? null);

        $icons = $payload['icons'] ?? null;
        self::assertIsArray($icons);
        self::assertEmpty($icons);
    }

    public function testAddAndList(): void
    {
        $this->registerSmileyIcon();
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/icons');
        $payload = $this->jsonResponse();
        self::assertSame(1, $payload['count'] ?? null);
    }

    public function testGetIconReturnsPng(): void
    {
        $this->registerSmileyIcon();
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/icons/smiley');
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $contentType = (string) $response->headers->get('Content-Type');
        self::assertStringContainsString('image/png', $contentType);
    }

    /**
     * POST /icons is declared in the OpenAPI spec as multipart/form-data, so
     * the validator requires the request to carry a real multipart body with
     * the matching Content-Type header. KernelBrowser does not synthesise
     * the multipart envelope automatically, so we craft the body by hand and
     * inject the Content-Type with the boundary. The controller stores names
     * only and ignores the binary content.
     */
    private function registerSmileyIcon(): void
    {
        $boundary = '----SimulatorBoundary'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"file\"; filename=\"smiley.png\"\r\n"
            ."Content-Type: image/png\r\n"
            ."\r\n"
            ."fake-png-bytes\r\n"
            ."--{$boundary}--\r\n";

        $this->client->request(
            method: 'POST',
            uri: '/icons?name=smiley',
            server: ['CONTENT_TYPE' => 'multipart/form-data; boundary='.$boundary],
            content: $body,
        );
    }

    public function testGetUnknownIconReturns404(): void
    {
        $this->client->request('GET', '/icons/notthere');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/icons?name=notthere');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }
}
