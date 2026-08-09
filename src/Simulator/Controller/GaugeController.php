<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\GaugeState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GaugeController extends AbstractSimulatorController
{
    #[Route('/gauges', methods: ['GET'])]
    public function listGauges(GaugeState $gauges): JsonResponse
    {
        $storedGauges = $gauges->list();
        $summaries = [];
        foreach ($storedGauges as $name => $payload) {
            $summaries[] = self::summarize($name, $payload);
        }

        return new JsonResponse([
            'gauges' => $summaries,
            'count' => \count($storedGauges),
        ]);
    }

    #[Route('/gauge', methods: ['GET'])]
    public function getGauge(Request $request, GaugeState $gauges): JsonResponse
    {
        $name = (string) $request->query->get('name');
        $payload = $gauges->get($name);
        if (null === $payload) {
            return $this->notFound();
        }

        return new JsonResponse(['name' => $name] + $payload);
    }

    #[Route('/gauge', methods: ['POST'])]
    public function upsertGauge(Request $request, GaugeState $gauges): JsonResponse
    {
        $body = $this->decodeJsonBody($request);

        $name = $this->resolveName($request, $body);
        if (null === $name) {
            return $this->badRequest('name is required');
        }

        $gauges->upsert($name, $body);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/gauge', methods: ['DELETE'])]
    public function deleteGauge(Request $request, GaugeState $gauges): JsonResponse
    {
        $name = (string) $request->query->get('name');
        if (!$gauges->delete($name)) {
            return $this->notFound();
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function summarize(string $name, array $payload): array
    {
        $summary = ['name' => $name];

        if (\array_key_exists('title', $payload)) {
            $summary['title'] = $payload['title'];
        }

        $rows = $payload['rows'] ?? null;
        $summary['rowCount'] = \is_array($rows) ? \count($rows) : 0;

        return $summary;
    }
}
