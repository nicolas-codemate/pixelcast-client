<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractSimulatorController extends AbstractController
{
    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonBody(Request $request): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    protected function notFound(string $message = 'not found'): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_NOT_FOUND);
    }

    protected function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function resolveName(Request $request, array $body): ?string
    {
        $queryName = $request->query->get('name');
        if (\is_string($queryName) && '' !== $queryName) {
            return $queryName;
        }

        $bodyName = $body['name'] ?? null;
        if (\is_string($bodyName) && '' !== $bodyName) {
            return $bodyName;
        }

        return null;
    }
}
