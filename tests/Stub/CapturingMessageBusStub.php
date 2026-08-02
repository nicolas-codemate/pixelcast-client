<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class CapturingMessageBusStub implements MessageBusInterface
{
    /**
     * @var list<object>
     */
    public array $dispatchedMessages = [];

    /**
     * @param StampInterface[] $stamps
     */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatchedMessages[] = $message;

        return new Envelope($message, $stamps);
    }
}
