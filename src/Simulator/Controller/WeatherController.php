<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\Projection\FreshnessProjection;
use App\Simulator\State\WeatherState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WeatherController extends AbstractSimulatorController
{
    public const int DEFAULT_STALE_AFTER_SECONDS = 3600;

    // A forecast that stopped being refreshed turns wrong rather than merely old, so the
    // contract falls back to the clock instead of dimming what is on screen.
    public const string DEFAULT_STALE_BEHAVIOR = 'hide';

    private const array STALE_DECLARATION_FIELDS = ['staleAfter', 'staleBehavior'];

    #[Route('/weather', methods: ['GET'])]
    public function getWeather(WeatherState $weather): JsonResponse
    {
        $current = $weather->current();
        $freshness = FreshnessProjection::of(
            $weather->staleDeclaration(),
            new \DateTimeImmutable(),
            $weather->lastUpdatedAt(),
            self::DEFAULT_STALE_AFTER_SECONDS,
            self::DEFAULT_STALE_BEHAVIOR,
        );

        // WeatherResponse declares no nullable property, so an empty state omits the keys
        // it has no value for rather than sending null.
        return new JsonResponse(array_filter(
            [
                'valid' => null !== $current,
                'age' => $freshness['age'],
                'stale' => $freshness['stale'],
                'staleAfter' => $freshness['staleAfter'],
                'staleBehavior' => $freshness['staleBehavior'],
                'current' => $current,
                'forecast' => $weather->forecast(),
                'today' => $weather->today(),
            ],
            static fn (mixed $value): bool => null !== $value,
        ));
    }

    #[Route('/weather', methods: ['POST'])]
    public function postWeather(Request $request, WeatherState $weather): JsonResponse
    {
        $body = $this->decodeJsonBody($request);

        $current = self::asStringKeyedArray($body['current'] ?? null);

        $forecast = [];
        $forecastRaw = $body['forecast'] ?? null;
        if (\is_array($forecastRaw)) {
            foreach ($forecastRaw as $entry) {
                $normalized = self::asStringKeyedArray($entry);
                if (null !== $normalized) {
                    $forecast[] = $normalized;
                }
            }
        }

        $weather->update(
            $current,
            $forecast,
            self::asStringKeyedArray($body['today'] ?? null),
            array_intersect_key($body, array_flip(self::STALE_DECLARATION_FIELDS)),
        );

        return new JsonResponse(['success' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function asStringKeyedArray(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $key => $element) {
            $normalized[(string) $key] = $element;
        }

        return $normalized;
    }
}
