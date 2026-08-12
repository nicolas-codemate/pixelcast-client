<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\SleepState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SleepController extends AbstractSimulatorController
{
    #[Route('/sleep', methods: ['GET'])]
    public function getSleep(SleepState $sleep): JsonResponse
    {
        $sleepReason = $sleep->sleepReasonAt(new \DateTimeImmutable());
        $state = ['sleeping' => null !== $sleepReason];

        if (null !== $sleepReason) {
            $state['reason'] = $sleepReason;
        }

        if (SleepState::REASON_OVERRIDE === $sleepReason) {
            $state['until'] = $sleep->overrideExpiryEpoch();
        }

        $state['config'] = $sleep->current();

        return new JsonResponse($state);
    }

    #[Route('/sleep', methods: ['POST'])]
    public function postSleep(Request $request, SleepState $sleep): JsonResponse
    {
        $sleep->patch($this->decodeJsonBody($request));

        return new JsonResponse(['success' => true]);
    }
}
