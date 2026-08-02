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
     * @param list<SyncOutcome>|null $outcomesInDispatchOrder null to return envelopes without any HandledStamp
     */
    public function __construct(
        private ?array $outcomesInDispatchOrder = null,
    ) {
    }

    /**
     * @param StampInterface[] $stamps
     */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatchedMessages[] = $message;

        $envelope = Envelope::wrap($message, $stamps);

        if (null === $this->outcomesInDispatchOrder) {
            return $envelope;
        }

        $outcome = array_shift($this->outcomesInDispatchOrder);
        if (null === $outcome) {
            throw new \LogicException('The stub ran out of outcomes: more messages were dispatched than expected.');
        }

        return $envelope->with(new HandledStamp($outcome, 'stub'));
    }
}
