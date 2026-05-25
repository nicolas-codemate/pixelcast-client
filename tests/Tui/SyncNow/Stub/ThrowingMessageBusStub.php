<?php

declare(strict_types=1);

namespace App\Tests\Tui\SyncNow\Stub;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ThrowingMessageBusStub implements MessageBusInterface
{
    public function __construct(
        private readonly \Throwable $exceptionToThrow,
    ) {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        throw $this->exceptionToThrow;
    }
}
