<?php

declare(strict_types=1);

namespace App\Tests\Client\Notification;

use App\Client\Color;
use App\Client\Notification\NotificationPayload;
use PHPUnit\Framework\TestCase;

final class NotificationPayloadTest extends TestCase
{
    public function testToArrayOfAMinimalPayloadCarriesTheTextAlone(): void
    {
        $payload = new NotificationPayload(text: 'Build finished');

        self::assertSame(['text' => 'Build finished'], $payload->toArray());
    }

    public function testToArrayEmitsEveryProvidedFieldUnderItsSpecKey(): void
    {
        $payload = new NotificationPayload(
            text: 'Build finished',
            notificationId: 'notif_42',
            iconName: 'rocket',
            textColor: Color::fromHexCode('#FF8800'),
            backgroundColor: Color::fromHexCode('#0096FF'),
            displayDurationMilliseconds: 5000,
            holdUntilDismissed: true,
            urgent: true,
            stackWithOtherNotifications: false,
        );

        self::assertSame(
            [
                'text' => 'Build finished',
                'id' => 'notif_42',
                'icon' => 'rocket',
                'color' => '#FF8800',
                'background' => '#0096FF',
                'duration' => 5000,
                'hold' => true,
                'urgent' => true,
                'stack' => false,
            ],
            $payload->toArray(),
        );
    }

    public function testToArrayEmitsABooleanExplicitlySetToFalse(): void
    {
        $payload = new NotificationPayload(text: 'Build finished', holdUntilDismissed: false);

        self::assertSame(['text' => 'Build finished', 'hold' => false], $payload->toArray());
    }

    public function testConstructorAcceptsOneHundredAndTwentyEightCharacters(): void
    {
        $payload = new NotificationPayload(text: str_repeat('a', 128));

        self::assertSame(128, mb_strlen($payload->text));
    }

    public function testConstructorRejectsOneHundredAndTwentyNineCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A notification text holds at most 128 characters, got 129.');

        new NotificationPayload(text: str_repeat('a', 129));
    }
}
