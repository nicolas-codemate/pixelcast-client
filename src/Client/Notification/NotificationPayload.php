<?php

declare(strict_types=1);

namespace App\Client\Notification;

use App\Client\Color;

final readonly class NotificationPayload
{
    private const int MAXIMUM_TEXT_LENGTH = 128;

    public function __construct(
        public string $text,
        public ?string $notificationId = null,
        public ?string $iconName = null,
        public ?Color $textColor = null,
        public ?Color $backgroundColor = null,
        public ?int $displayDurationMilliseconds = null,
        public ?bool $holdUntilDismissed = null,
        public ?bool $urgent = null,
        public ?bool $stackWithOtherNotifications = null,
    ) {
        if (mb_strlen($this->text) > self::MAXIMUM_TEXT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A notification text holds at most %d characters, got %d.', self::MAXIMUM_TEXT_LENGTH, mb_strlen($this->text)));
        }
    }

    /**
     * @return array{text: string, id?: string, icon?: string, color?: string, background?: string, duration?: int, hold?: bool, urgent?: bool, stack?: bool}
     */
    public function toArray(): array
    {
        $payload = ['text' => $this->text];

        if (null !== $this->notificationId) {
            $payload['id'] = $this->notificationId;
        }

        if (null !== $this->iconName) {
            $payload['icon'] = $this->iconName;
        }

        if (null !== $this->textColor) {
            $payload['color'] = $this->textColor->hexCode;
        }

        if (null !== $this->backgroundColor) {
            $payload['background'] = $this->backgroundColor->hexCode;
        }

        if (null !== $this->displayDurationMilliseconds) {
            $payload['duration'] = $this->displayDurationMilliseconds;
        }

        if (null !== $this->holdUntilDismissed) {
            $payload['hold'] = $this->holdUntilDismissed;
        }

        if (null !== $this->urgent) {
            $payload['urgent'] = $this->urgent;
        }

        if (null !== $this->stackWithOtherNotifications) {
            $payload['stack'] = $this->stackWithOtherNotifications;
        }

        return $payload;
    }
}
