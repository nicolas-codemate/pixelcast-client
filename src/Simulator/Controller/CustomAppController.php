<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\Projection\FreshnessProjection;
use App\Simulator\State\CustomAppState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CustomAppController extends AbstractSimulatorController
{
    public const int DEFAULT_STALE_AFTER_SECONDS = 0;
    public const string DEFAULT_STALE_BEHAVIOR = 'hide';

    private const array PROJECTED_FIELDS = ['text', 'icon', 'label', 'color', 'duration'];

    #[Route('/apps', methods: ['GET'])]
    public function listApps(CustomAppState $apps): JsonResponse
    {
        $storedApps = $apps->list();
        $now = new \DateTimeImmutable();

        $projected = [];
        foreach ($storedApps as $name => $payload) {
            $projected[] = self::projectAppResponse($name, $payload, $now, $apps->pushedAt($name));
        }

        return new JsonResponse([
            'apps' => $projected,
            'count' => \count($storedApps),
            'currentIndex' => [] === $storedApps ? -1 : 0,
            'rotationEnabled' => true,
        ]);
    }

    #[Route('/custom', methods: ['POST'])]
    public function upsertCustom(Request $request, CustomAppState $apps): JsonResponse
    {
        $body = $this->decodeJsonBody($request);

        $name = $this->resolveName($request, $body);
        if (null === $name) {
            return $this->badRequest('name is required');
        }

        $apps->upsert($name, $body);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/custom', methods: ['DELETE'])]
    public function deleteCustom(Request $request, CustomAppState $apps): JsonResponse
    {
        $name = (string) $request->query->get('name');
        if (!$apps->delete($name)) {
            return $this->notFound();
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function projectAppResponse(string $name, array $payload, \DateTimeImmutable $now, ?\DateTimeImmutable $pushedAt): array
    {
        $zonesRaw = $payload['zones'] ?? null;
        $zones = \is_array($zonesRaw) ? array_values(array_filter(
            $zonesRaw,
            static fn (mixed $entry): bool => \is_array($entry),
        )) : [];

        $projection = [
            'id' => $name,
            'isSystem' => false,
            'isCurrent' => false,
            'zoneCount' => \count($zones),
        ];

        foreach (self::PROJECTED_FIELDS as $field) {
            if (\array_key_exists($field, $payload)) {
                $projection[$field] = $payload[$field];
            }
        }

        if ([] !== $zones) {
            $projection['zones'] = $zones;
        }

        $freshness = FreshnessProjection::of(
            $payload,
            $pushedAt,
            $now,
            self::DEFAULT_STALE_AFTER_SECONDS,
            self::DEFAULT_STALE_BEHAVIOR,
        );
        // AppResponse says whether the app is stale, never how old it is.
        unset($freshness['age']);

        return $projection + $freshness;
    }
}
