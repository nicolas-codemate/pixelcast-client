<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\Projection\FreshnessProjection;
use App\Simulator\State\GaugeState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GaugeController extends AbstractSimulatorController
{
    public const int DEFAULT_STALE_AFTER_SECONDS = 3600;
    public const string DEFAULT_STALE_BEHAVIOR = 'dim';

    #[Route('/gauges', methods: ['GET'])]
    public function listGauges(GaugeState $gauges): JsonResponse
    {
        $storedGauges = $gauges->list();
        $now = new \DateTimeImmutable();

        $summaries = [];
        foreach ($storedGauges as $name => $payload) {
            $summaries[] = self::summarize($name, $payload, $now, $gauges->pushedAt($name));
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
    private static function summarize(string $name, array $payload, \DateTimeImmutable $now, ?\DateTimeImmutable $pushedAt): array
    {
        $summary = ['name' => $name];

        if (\array_key_exists('title', $payload)) {
            $summary['title'] = self::titleAsPlainText($payload['title']);
        }

        $rows = $payload['rows'] ?? null;
        $summary['rowCount'] = \is_array($rows) ? \count($rows) : 0;

        return $summary + FreshnessProjection::of(
            $payload,
            $pushedAt,
            $now,
            self::DEFAULT_STALE_AFTER_SECONDS,
            self::DEFAULT_STALE_BEHAVIOR,
        );
    }

    /**
     * A title arrives as plain text, as a single colored string or as colored segments; the list
     * shows the text of whichever form was pushed, colors dropped.
     */
    private static function titleAsPlainText(mixed $title): string
    {
        if (\is_string($title)) {
            return $title;
        }

        if (!\is_array($title)) {
            return '';
        }

        $coloredString = $title['text'] ?? null;
        if (\is_string($coloredString)) {
            return $coloredString;
        }

        $segmentTexts = [];
        foreach ($title as $segment) {
            if (!\is_array($segment)) {
                continue;
            }

            $segmentText = $segment['t'] ?? null;
            if (\is_string($segmentText)) {
                $segmentTexts[] = $segmentText;
            }
        }

        return implode('', $segmentTexts);
    }
}
