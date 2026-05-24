<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class IndicatorControllerTest extends SimulatorWebTestCase
{
    public function testSetSlot1(): void
    {
        $this->postJson('/indicator1', [
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
        $this->deleteRequest('/indicator2');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('off', $payload['mode'] ?? null);
    }
}
