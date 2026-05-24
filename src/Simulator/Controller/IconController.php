<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\IconState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IconController extends AbstractSimulatorController
{
    private const int SIMULATED_FILESYSTEM_TOTAL_BYTES = 1_048_576;

    private const string PNG_1X1_TRANSPARENT = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDAT\x78\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    #[Route('/icons', methods: ['GET'])]
    public function listIcons(IconState $icons): JsonResponse
    {
        $entries = $icons->list();
        $used = array_sum(array_map(
            static fn (array $entry): int => \is_int($entry['size'] ?? null) ? $entry['size'] : 0,
            $entries,
        ));

        return new JsonResponse([
            'icons' => array_map(
                static fn (array $entry): array => self::projectIconInfo($entry),
                $entries,
            ),
            'count' => \count($entries),
            'storage' => [
                'used' => $used,
                'total' => self::SIMULATED_FILESYSTEM_TOTAL_BYTES,
            ],
        ]);
    }

    #[Route('/icons', methods: ['POST'])]
    public function addIcon(Request $request, IconState $icons): JsonResponse
    {
        $name = (string) $request->query->get('name');
        $icons->register($name);

        return new JsonResponse([
            'success' => true,
            'name' => $name,
        ]);
    }

    #[Route('/icons', methods: ['DELETE'])]
    public function removeIcon(Request $request, IconState $icons): JsonResponse
    {
        $name = (string) $request->query->get('name');
        if (!$icons->delete($name)) {
            return $this->notFound();
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/icons/{name}', methods: ['GET'], requirements: ['name' => '[A-Za-z0-9_-]+'])]
    public function getIcon(string $name, IconState $icons): Response
    {
        if (!$icons->has($name)) {
            return $this->notFound();
        }

        return new Response(self::PNG_1X1_TRANSPARENT, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
        ]);
    }

    #[Route('/icons/lametric', methods: ['POST'])]
    public function downloadLametric(Request $request, IconState $icons): JsonResponse
    {
        $body = $this->decodeJsonBody($request);
        $id = $body['id'] ?? null;
        \assert(\is_int($id));

        $name = 'lametric_'.$id;
        $icons->register($name);

        return new JsonResponse([
            'success' => true,
            'id' => $id,
            'name' => $name,
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function projectIconInfo(array $entry): array
    {
        $name = $entry['name'] ?? '';
        $type = $entry['type'] ?? 'png';
        $size = $entry['size'] ?? 0;

        return [
            'name' => \is_string($name) ? $name : '',
            'filename' => \is_string($name) && \is_string($type) ? $name.'.'.$type : '',
            'size' => \is_int($size) ? $size : 0,
        ];
    }
}
