<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\CustomAppState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CustomAppController extends AbstractSimulatorController
{
    private const array PROJECTED_FIELDS = ['text', 'icon', 'label', 'color', 'duration', 'lifetime', 'priority'];

    #[Route('/apps', methods: ['GET'])]
    public function listApps(CustomAppState $apps): JsonResponse
    {
        $entries = $apps->list();
        $projected = array_map(
            static fn (array $payload): array => self::projectAppResponse($payload),
            $entries,
        );

        return new JsonResponse([
            'apps' => $projected,
            'count' => \count($entries),
            'currentIndex' => [] === $entries ? -1 : 0,
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
    private static function projectAppResponse(array $payload): array
    {
        $zonesRaw = $payload['zones'] ?? null;
        $zones = \is_array($zonesRaw) ? array_values(array_filter(
            $zonesRaw,
            static fn (mixed $entry): bool => \is_array($entry),
        )) : [];

        $name = $payload['name'] ?? null;
        $id = \is_string($name) ? $name : '';

        $projection = [
            'id' => $id,
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

        return $projection;
    }
}
