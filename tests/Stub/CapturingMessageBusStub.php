<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Message\SyncOutcome;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class CapturingMessageBusStub implements MessageBusInterface
{
    /**
     * @var list<object>
     */
    public array $dispatchedMessages = [];

    /**
     * @param list<SyncOutcome> $outcomesInDispatchOrder leave empty to return envelopes without any HandledStamp
     */
    public function __construct(
        private array $outcomesInDispatchOrder = [],
    ) {
    }

    /**
     * @param StampInterface[] $stamps
     */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatchedMessages[] = $message;

        $envelope = Envelope::wrap($message, $stamps);

        if ([] === $this->outcomesInDispatchOrder) {
            return $envelope;
        }

        $outcome = array_shift($this->outcomesInDispatchOrder);

        return $envelope->with(new HandledStamp($outcome, 'stub'));
    }
}
