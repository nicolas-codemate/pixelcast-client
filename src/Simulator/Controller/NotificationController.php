<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\NotificationState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController extends AbstractSimulatorController
{
    public const int DEFAULT_DURATION_MILLISECONDS = 5_000;
    public const bool DEFAULT_HOLD = false;
    public const bool DEFAULT_URGENT = false;
    public const bool DEFAULT_STACK = true;

    private const array ECHOED_FIELDS = ['text', 'icon'];

    #[Route('/notify', methods: ['POST'])]
    public function postNotify(Request $request, NotificationState $notifications): JsonResponse
    {
        $body = $this->decodeJsonBody($request);

        $urgentRaw = $body['urgent'] ?? null;
        $urgent = \is_bool($urgentRaw) ? $urgentRaw : self::DEFAULT_URGENT;

        $id = $notifications->enqueue($body, $urgent);

        return new JsonResponse([
            'success' => true,
            'id' => $id,
        ]);
    }

    #[Route('/notify/list', methods: ['GET'])]
    public function listNotify(NotificationState $notifications): JsonResponse
    {
        $currentIndex = $notifications->currentIndex();

        $projected = [];
        foreach ($notifications->list() as $position => $entry) {
            $projected[] = self::projectNotificationItem($entry, $position === $currentIndex);
        }

        return new JsonResponse([
            'count' => $notifications->count(),
            'currentIndex' => $currentIndex,
            'notifications' => $projected,
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

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function projectNotificationItem(array $entry, bool $isCurrent): array
    {
        $projection = ['id' => $entry['id'] ?? ''];

        foreach (self::ECHOED_FIELDS as $field) {
            if (\array_key_exists($field, $entry)) {
                $projection[$field] = $entry[$field];
            }
        }

        return $projection + [
            'duration' => \is_int($entry['duration'] ?? null) ? $entry['duration'] : self::DEFAULT_DURATION_MILLISECONDS,
            'hold' => \is_bool($entry['hold'] ?? null) ? $entry['hold'] : self::DEFAULT_HOLD,
            'urgent' => \is_bool($entry['urgent'] ?? null) ? $entry['urgent'] : self::DEFAULT_URGENT,
            'stack' => \is_bool($entry['stack'] ?? null) ? $entry['stack'] : self::DEFAULT_STACK,
            'displayed' => true === ($entry['displayed'] ?? null),
            'current' => $isCurrent,
        ];
    }
}
