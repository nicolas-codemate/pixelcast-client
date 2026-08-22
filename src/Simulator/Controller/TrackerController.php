<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\Projection\FreshnessProjection;
use App\Simulator\State\TrackerState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TrackerController extends AbstractSimulatorController
{
    public const int DEFAULT_STALE_AFTER_SECONDS = 3600;
    public const string DEFAULT_STALE_BEHAVIOR = 'dim';

    private const array SUMMARY_FIELDS = ['symbol', 'value', 'change'];

    #[Route('/trackers', methods: ['GET'])]
    public function listTrackers(TrackerState $trackers): JsonResponse
    {
        $storedTrackers = $trackers->list();
        $now = new \DateTimeImmutable();

        $summaries = [];
        foreach ($storedTrackers as $name => $payload) {
            $summaries[] = self::summarize($name, $payload, $now, $trackers->pushedAt($name));
        }

        return new JsonResponse([
            'trackers' => $summaries,
            'count' => \count($storedTrackers),
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
    private static function summarize(string $name, array $payload, \DateTimeImmutable $now, ?\DateTimeImmutable $pushedAt): array
    {
        $summary = ['name' => $name];
        foreach (self::SUMMARY_FIELDS as $field) {
            if (\array_key_exists($field, $payload)) {
                $summary[$field] = $payload[$field];
            }
        }

        return $summary + FreshnessProjection::of(
            $payload,
            $now,
            $pushedAt,
            self::DEFAULT_STALE_AFTER_SECONDS,
            self::DEFAULT_STALE_BEHAVIOR,
        );
    }
}
