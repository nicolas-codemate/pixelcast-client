<?php

declare(strict_types=1);

namespace App\Tests\Tui\SyncNow\Stub;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CapturingMessageBusStub implements MessageBusInterface
{
    /**
     * @var list<object>
     */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}
