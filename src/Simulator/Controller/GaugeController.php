<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\Projection\ColorProjection;
use App\Simulator\Projection\FreshnessProjection;
use App\Simulator\State\GaugeState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GaugeController extends AbstractSimulatorController
{
    public const int DEFAULT_STALE_AFTER_SECONDS = 3600;
    public const string DEFAULT_STALE_BEHAVIOR = 'dim';
    public const int DEFAULT_ROW_PERCENT = 0;
    public const string DEFAULT_ROW_COLOR = '#FFFFFF';
    public const string DEFAULT_ROW_NOTE_COLOR = '#969696';

    private const array ECHOED_ROW_FIELDS = ['label', 'info', 'value', 'note', 'color', 'noteColor'];
    private const array COLORED_ROW_FIELDS = ['color', 'noteColor'];

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

        $freshness = FreshnessProjection::of(
            $payload,
            new \DateTimeImmutable(),
            $gauges->pushedAt($name),
            self::DEFAULT_STALE_AFTER_SECONDS,
            self::DEFAULT_STALE_BEHAVIOR,
        );

        // The projection comes before the stored payload so that staleAfter and staleBehavior
        // answer the value the simulator actually applies, not whatever was pushed under them.
        return new JsonResponse(['name' => $name] + $freshness + self::withProjectedRows($payload));
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
    private static function withProjectedRows(array $payload): array
    {
        $storedRows = $payload['rows'] ?? null;
        if (!\is_array($storedRows)) {
            return $payload;
        }

        $projectedRows = [];
        foreach ($storedRows as $storedRow) {
            if (\is_array($storedRow)) {
                $projectedRows[] = self::projectGaugeRow($storedRow);
            }
        }

        $payload['rows'] = $projectedRows;

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $storedRow
     *
     * @return array<string, mixed>
     */
    private static function projectGaugeRow(array $storedRow): array
    {
        $projection = [];
        foreach (self::ECHOED_ROW_FIELDS as $field) {
            if (\array_key_exists($field, $storedRow)) {
                $projection[$field] = $storedRow[$field];
            }
        }

        return ColorProjection::withColorFieldsAsHexCodes($projection, ...self::COLORED_ROW_FIELDS) + [
            'percent' => \is_int($storedRow['percent'] ?? null) ? $storedRow['percent'] : self::DEFAULT_ROW_PERCENT,
            'color' => self::DEFAULT_ROW_COLOR,
            'noteColor' => self::DEFAULT_ROW_NOTE_COLOR,
        ];
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
            $now,
            $pushedAt,
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

        return implode('', array_filter(array_column($title, 't'), \is_string(...)));
    }
}
