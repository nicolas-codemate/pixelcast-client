<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class IndicatorControllerTest extends SimulatorWebTestCase
{
    public function testSetSlot1(): void
    {
        $this->postJson('/api/indicator1', [
            'mode' => 'solid',
            'color' => '#FF0000',
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $payload = $this->jsonResponse();
        self::assertSame(1, $payload['indicator'] ?? null);
    }

    public function testClearSlot2(): void
    {
        $this->deleteRequest('/api/indicator2');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('off', $payload['mode'] ?? null);
    }

    public function testALitModeWithoutAColorKeepsTheColorTheSlotAlreadyCarries(): void
    {
        $this->postJson('/api/indicator3', ['mode' => 'solid', 'color' => '#00FF00']);
        $this->postJson('/api/indicator3', ['mode' => 'blink', 'blinkInterval' => 1000]);

        $payload = $this->jsonResponse();
        self::assertSame('blink', $payload['mode'] ?? null);
        self::assertSame('#00FF00', $payload['color'] ?? null);
    }

    public function testATurnedOffSlotStillKnowsTheColorItCarried(): void
    {
        $this->postJson('/api/indicator1', ['mode' => 'solid', 'color' => '#FF0000']);
        $this->deleteRequest('/api/indicator1');
        $this->postJson('/api/indicator1', ['mode' => 'solid']);

        self::assertSame('#FF0000', $this->jsonResponse()['color'] ?? null);
    }

    public function testTurningAnIndicatorOffCarriesNoColorBack(): void
    {
        $this->postJson('/api/indicator2', ['mode' => 'solid', 'color' => '#0000FF']);
        $this->postJson('/api/indicator2', ['mode' => 'off']);

        $payload = $this->jsonResponse();
        self::assertSame('off', $payload['mode'] ?? null);
        self::assertArrayNotHasKey('color', $payload);
    }
}
