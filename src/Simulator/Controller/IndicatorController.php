<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\IndicatorState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class IndicatorController extends AbstractSimulatorController
{
    #[Route('/indicator{slot}', methods: ['POST'], requirements: ['slot' => '[1-3]'])]
    public function setIndicator(string $slot, Request $request, IndicatorState $indicators): JsonResponse
    {
        $body = $this->decodeJsonBody($request);
        $slotNumber = (int) $slot;
        $indicators->set($slotNumber, $body);

        $response = [
            'success' => true,
            'indicator' => $slotNumber,
        ];

        $mode = $body['mode'] ?? null;
        if (\is_string($mode)) {
            $response['mode'] = $mode;
        }

        $color = $body['color'] ?? null;
        if (\is_string($color)) {
            $response['color'] = $color;
        }

        return new JsonResponse($response);
    }

    #[Route('/indicator{slot}', methods: ['DELETE'], requirements: ['slot' => '[1-3]'])]
    public function clearIndicator(string $slot, IndicatorState $indicators): JsonResponse
    {
        $indicators->clear((int) $slot);

        return new JsonResponse([
            'success' => true,
            'mode' => 'off',
        ]);
    }
}
