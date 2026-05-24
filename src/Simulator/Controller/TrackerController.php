<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\TrackerState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TrackerController extends AbstractSimulatorController
{
    private const array SUMMARY_FIELDS = ['name', 'symbol', 'value', 'change'];

    #[Route('/trackers', methods: ['GET'])]
    public function listTrackers(TrackerState $trackers): JsonResponse
    {
        $entries = $trackers->list();
        $summaries = array_map(
            static fn (array $payload): array => self::summarize($payload),
            $entries,
        );

        return new JsonResponse([
            'trackers' => $summaries,
            'count' => \count($entries),
        ]);
    }

    #[Route('/tracker', methods: ['GET'])]
    public function getTracker(Request $request, TrackerState $trackers): JsonResponse
    {
        $name = (string) $request->query->get('name');
        $payload = $trackers->get($name);
        if (null === $payload) {
            return $this->notFound();
        }

        return new JsonResponse(['name' => $name] + $payload);
    }

    #[Route('/tracker', methods: ['POST'])]
    public function upsertTracker(Request $request, TrackerState $trackers): JsonResponse
    {
        $body = $this->decodeJsonBody($request);

        $name = $this->resolveName($request, $body);
        if (null === $name) {
            return $this->badRequest('name is required');
        }

        $trackers->upsert($name, $body);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/tracker', methods: ['DELETE'])]
    public function deleteTracker(Request $request, TrackerState $trackers): JsonResponse
    {
        $name = (string) $request->query->get('name');
        if (!$trackers->delete($name)) {
            return $this->notFound();
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function summarize(array $payload): array
    {
        $summary = [];
        foreach (self::SUMMARY_FIELDS as $field) {
            if (\array_key_exists($field, $payload)) {
                $summary[$field] = $payload[$field];
            }
        }

        return $summary;
    }
}
