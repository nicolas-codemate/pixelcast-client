<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\SimulatorStateStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ResetController extends AbstractSimulatorController
{
    public function __construct(
        private readonly SimulatorStateStore $stateStore,
    ) {
    }

    #[Route('/__reset', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $this->stateStore->purge();

        return new JsonResponse(['success' => true]);
    }
}
