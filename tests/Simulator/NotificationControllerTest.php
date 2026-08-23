<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class NotificationControllerTest extends SimulatorWebTestCase
{
    public function testEnqueueAndList(): void
    {
        $this->postJson('/api/notify', [
            'text' => 'New message!',
            'icon' => 'mail',
            'color' => '#0096FF',
            'duration' => 5_000,
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $payload = $this->jsonResponse();
        self::assertTrue($payload['success'] ?? null);
        self::assertArrayHasKey('id', $payload);
        self::assertIsString($payload['id']);

        $this->client->request('GET', '/api/notify/list');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $listPayload = $this->jsonResponse();
        self::assertGreaterThanOrEqual(1, $listPayload['count'] ?? 0);
    }

    public function testAPushIsRelistedAsTheContractDeclaresTheItem(): void
    {
        $this->postJson('/api/notify', [
            ...self::notificationPushPayload(),
            'id' => 'notif_full',
            'color' => '#0096FF',
        ]);

        self::assertSame([
            'id' => 'notif_full',
            'text' => 'New message!',
            'icon' => 'mail',
            'duration' => 12_000,
            'hold' => true,
            'urgent' => true,
            'stack' => false,
            'displayed' => true,
            'current' => true,
        ], $this->firstItemOfList('/api/notify/list', 'notifications'));
    }

    public function testAMinimalPushIsListedWithTheDefaultsTheContractDeclares(): void
    {
        $this->postJson('/api/notify', ['text' => 'hello', 'id' => 'notif_minimal']);

        self::assertSame([
            'id' => 'notif_minimal',
            'text' => 'hello',
            'duration' => 5_000,
            'hold' => false,
            'urgent' => false,
            'stack' => true,
            'displayed' => true,
            'current' => true,
        ], $this->firstItemOfList('/api/notify/list', 'notifications'));
    }

    public function testAnUrgentNotificationBecomesTheCurrentOneAndTheOtherStaysDisplayed(): void
    {
        $this->postJson('/api/notify', ['text' => 'first', 'id' => 'notif_first']);
        $this->postJson('/api/notify', ['text' => 'urgent', 'id' => 'notif_urgent', 'urgent' => true]);

        $listedNotifications = $this->itemsOfList('/api/notify/list', 'notifications');

        self::assertSame(['notif_urgent', 'notif_first'], array_column($listedNotifications, 'id'));
        self::assertSame([true, true], array_column($listedNotifications, 'displayed'));
        self::assertSame([true, false], array_column($listedNotifications, 'current'));
    }

    public function testDismissEmptyReturns404(): void
    {
        $this->client->request('POST', '/api/notify/dismiss');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('error', $payload);
    }

    public function testDismissNonEmpty(): void
    {
        $this->postJson('/api/notify', ['text' => 'hello']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/notify/dismiss');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertTrue($payload['success'] ?? null);
    }
}
