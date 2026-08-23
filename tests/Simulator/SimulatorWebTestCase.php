<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class SimulatorWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // KernelBrowser reboots the kernel between requests by default, which
        // rebuilds the container on every call. Pin it for the whole test so
        // successive requests behave like successive php -S processes reading
        // the same persisted state; the reset below is what isolates tests.
        $this->client->disableReboot();
        $this->client->request('POST', '/api/__reset');
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function postJson(string $path, array $body): KernelBrowser
    {
        $this->client->request(
            method: 'POST',
            uri: $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return $this->client;
    }

    protected function deleteRequest(string $path): KernelBrowser
    {
        $this->client->request('DELETE', $path);

        return $this->client;
    }

    /**
     * POST /icons is declared in the OpenAPI spec as multipart/form-data, so the validator
     * requires a real multipart body with the matching Content-Type header. KernelBrowser does
     * not synthesise the envelope, so it is crafted by hand; the controller stores names only
     * and ignores the binary content.
     */
    protected function uploadIcon(string $name): KernelBrowser
    {
        $boundary = '----SimulatorBoundary'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"file\"; filename=\"{$name}.png\"\r\n"
            ."Content-Type: image/png\r\n"
            ."\r\n"
            ."fake-png-bytes\r\n"
            ."--{$boundary}--\r\n";

        $this->client->request(
            method: 'POST',
            uri: '/api/icons?name='.$name,
            server: ['CONTENT_TYPE' => 'multipart/form-data; boundary='.$boundary],
            content: $body,
        );

        return $this->client;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function trackerPushPayload(): array
    {
        return [
            'symbol' => 'BTC',
            'currency' => 'USD',
            'value' => 98452.30,
            'change' => 2.14,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function customAppPushPayload(): array
    {
        return [
            'text' => 'hello',
            'icon' => 'smiley',
            'color' => '#FF8800',
            'duration' => 10_000,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function notificationPushPayload(): array
    {
        return [
            'text' => 'New message!',
            'icon' => 'mail',
            'duration' => 12_000,
            'hold' => true,
            'urgent' => true,
            'stack' => false,
        ];
    }

    /**
     * @return list<array<mixed>>
     */
    protected function itemsOfList(string $path, string $listKey): array
    {
        $this->client->request('GET', $path);
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $listedItems = $this->jsonResponse()[$listKey] ?? null;
        self::assertIsArray($listedItems);

        $items = [];
        foreach ($listedItems as $listedItem) {
            self::assertIsArray($listedItem);
            $items[] = $listedItem;
        }

        return $items;
    }

    /**
     * @return array<mixed>
     */
    protected function firstItemOfList(string $path, string $listKey): array
    {
        $items = $this->itemsOfList($path, $listKey);
        self::assertArrayHasKey(0, $items);

        return $items[0];
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        \assert(\is_string($content));
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        $stringKeyed = [];
        foreach ($decoded as $key => $value) {
            $stringKeyed[(string) $key] = $value;
        }

        return $stringKeyed;
    }
}
