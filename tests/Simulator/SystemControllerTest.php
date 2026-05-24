<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class SystemControllerTest extends SimulatorWebTestCase
{
    public function testStatsReturns200AndBrightness(): void
    {
        $this->client->request('GET', '/stats');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('brightness', $payload);
    }

    public function testRebootReturnsSuccessImmediately(): void
    {
        $this->client->request('POST', '/reboot');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertTrue($payload['success'] ?? false);

        // Process must still be alive after a simulated reboot.
        $this->client->request('GET', '/stats');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testPostBrightnessUpdatesStats(): void
    {
        $this->postJson('/brightness', ['brightness' => 200]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/stats');
        $payload = $this->jsonResponse();
        self::assertSame(200, $payload['brightness'] ?? null);
    }

    public function testGetSettingsReturnsDefaults(): void
    {
        $this->client->request('GET', '/settings');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('autoRotate', $payload);
    }

    public function testPostSettingsPatch(): void
    {
        $this->postJson('/settings', ['autoRotate' => false]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/settings');
        $payload = $this->jsonResponse();
        self::assertFalse($payload['autoRotate'] ?? null);
    }
}
