<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\NotificationState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController extends AbstractSimulatorController
{
    #[Route('/notify', methods: ['POST'])]
    public function postNotify(Request $request, NotificationState $notifications): JsonResponse
    {
        $body = $this->decodeJsonBody($request);

        $urgentRaw = $body['urgent'] ?? null;
        $urgent = \is_bool($urgentRaw) ? $urgentRaw : false;

        $id = $notifications->enqueue($body, $urgent);

        return new JsonResponse([
            'success' => true,
            'id' => $id,
        ]);
    }

    #[Route('/notify/list', methods: ['GET'])]
    public function listNotify(NotificationState $notifications): JsonResponse
    {
        $count = $notifications->count();

        return new JsonResponse([
            'count' => $count,
            'currentIndex' => 0 === $count ? -1 : 0,
            'notifications' => $notifications->list(),
        ]);
    }

    #[Route('/notify/dismiss', methods: ['POST'])]
    public function dismissNotify(NotificationState $notifications): JsonResponse
    {
        if (!$notifications->dismissCurrent()) {
            return $this->notFound('no active notification');
        }

        return new JsonResponse(['success' => true]);
    }
}
