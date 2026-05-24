<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class SimulatorWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // KernelBrowser reboots the kernel between requests by default, which
        // discards in-memory simulator state. Pin the kernel for the whole
        // test so successive requests share the same state container.
        $this->client->disableReboot();
        $this->client->request('POST', '/__reset');
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
