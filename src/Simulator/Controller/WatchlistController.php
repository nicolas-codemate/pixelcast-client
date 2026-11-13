<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\Projection\ColorProjection;
use App\Simulator\Projection\FreshnessProjection;
use App\Simulator\State\WatchlistState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WatchlistController extends AbstractSimulatorController
{
    public const int DEFAULT_STALE_AFTER_SECONDS = 3600;
    public const string DEFAULT_STALE_BEHAVIOR = 'dim';
    public const string DEFAULT_ROW_SYMBOL_COLOR = '#FFB000';

    // The firmware stores every row field and echoes the ones a push left out as empty or zero.
    private const array DEFAULT_ROW_FIELDS = [
        'symbol' => '',
        'icon' => '',
        'currency' => '',
        'value' => 0.0,
        'change' => 0.0,
    ];

    #[Route('/watchlists', methods: ['GET'])]
    public function listWatchlists(WatchlistState $watchlists): JsonResponse
    {
        $storedWatchlists = $watchlists->list();
        $now = new \DateTimeImmutable();

        $summaries = [];
        foreach ($storedWatchlists as $name => $payload) {
            $rows = $payload['rows'] ?? null;
            $summaries[] = [
                'name' => $name,
                'rowCount' => \is_array($rows) ? \count($rows) : 0,
            ] + FreshnessProjection::of(
                $payload,
                $now,
                $watchlists->pushedAt($name),
                self::DEFAULT_STALE_AFTER_SECONDS,
                self::DEFAULT_STALE_BEHAVIOR,
            );
        }

        return new JsonResponse([
            'watchlists' => $summaries,
            'count' => \count($storedWatchlists),
        ]);
    }

    #[Route('/watchlist', methods: ['GET'])]
    public function getWatchlist(Request $request, WatchlistState $watchlists): JsonResponse
    {
        $name = (string) $request->query->get('name');
        $payload = $watchlists->get($name);
        if (null === $payload) {
            return $this->notFound();
        }

        $freshness = FreshnessProjection::of(
            $payload,
            new \DateTimeImmutable(),
            $watchlists->pushedAt($name),
            self::DEFAULT_STALE_AFTER_SECONDS,
            self::DEFAULT_STALE_BEHAVIOR,
        );

        return new JsonResponse(['name' => $name, 'rows' => self::projectedRowsOf($payload)] + $freshness);
    }

    #[Route('/watchlist', methods: ['POST'])]
    public function upsertWatchlist(Request $request, WatchlistState $watchlists): JsonResponse
    {
        $body = $this->decodeJsonBody($request);

        $name = $this->resolveName($request, $body);
        if (null === $name) {
            return $this->badRequest('name is required');
        }

        // A push without rows keeps the stored ones, as the firmware does
        $storedRows = $watchlists->get($name)['rows'] ?? null;
        if (!\array_key_exists('rows', $body) && null !== $storedRows) {
            $body['rows'] = $storedRows;
        }

        $watchlists->upsert($name, $body);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/watchlist', methods: ['DELETE'])]
    public function deleteWatchlist(Request $request, WatchlistState $watchlists): JsonResponse
    {
        $name = (string) $request->query->get('name');
        if (!$watchlists->delete($name)) {
            return $this->notFound();
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private static function projectedRowsOf(array $payload): array
    {
        $storedRows = $payload['rows'] ?? null;
        if (!\is_array($storedRows)) {
            return [];
        }

        $projectedRows = [];
        foreach ($storedRows as $storedRow) {
            if (\is_array($storedRow)) {
                $projectedRows[] = self::projectWatchlistRow($storedRow);
            }
        }

        return $projectedRows;
    }

    /**
     * @param array<array-key, mixed> $storedRow
     *
     * @return array<string, mixed>
     */
    private static function projectWatchlistRow(array $storedRow): array
    {
        $projection = [];
        foreach (self::DEFAULT_ROW_FIELDS as $field => $defaultValue) {
            $projection[$field] = $storedRow[$field] ?? $defaultValue;
        }

        if (\array_key_exists('symbolColor', $storedRow)) {
            $projection['symbolColor'] = $storedRow['symbolColor'];
        }

        return ColorProjection::withColorFieldsAsHexCodes($projection, 'symbolColor') + [
            'symbolColor' => self::DEFAULT_ROW_SYMBOL_COLOR,
        ];
    }
}
