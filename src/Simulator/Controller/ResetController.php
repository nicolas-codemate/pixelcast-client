<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\Logging\RequestLog;
use App\Simulator\State\ResettableState;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ResetController extends AbstractSimulatorController
{
    /**
     * @param iterable<ResettableState> $states
     */
    public function __construct(
        #[AutowireIterator('app.simulator_state')]
        private readonly iterable $states,
        private readonly RequestLog $requestLog,
    ) {
    }

    #[Route('/__reset', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        foreach ($this->states as $service) {
            $service->reset();
        }

        $this->requestLog->reset();

        return new JsonResponse(['success' => true]);
    }
}
