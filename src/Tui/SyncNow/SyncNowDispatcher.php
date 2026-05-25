<?php

declare(strict_types=1);

namespace App\Tui\SyncNow;

use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SyncNowDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function dispatch(SyncTarget $target): SyncNowResult
    {
        return $this->dispatchClass($target->messageClass());
    }

    public function dispatchClass(string $messageClass): SyncNowResult
    {
        if (!class_exists($messageClass)) {
            return SyncNowResult::notWired($messageClass);
        }

        try {
            $message = new $messageClass();
            $this->messageBus->dispatch($message);
        } catch (\Throwable $error) {
            return SyncNowResult::dispatchError($error->getMessage());
        }

        return SyncNowResult::dispatched($messageClass);
    }
}
