<?php

declare(strict_types=1);

namespace App\Simulator\EventListener;

use App\Simulator\State\SimulatorStateStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'loadPersistedState', priority: 512)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'savePersistedState')]
final readonly class PersistSimulatorStateListener
{
    public function __construct(
        private SimulatorStateStore $stateStore,
    ) {
    }

    public function loadPersistedState(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->stateStore->load();
    }

    public function savePersistedState(TerminateEvent $event): void
    {
        $this->stateStore->save();
    }
}
