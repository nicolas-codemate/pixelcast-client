<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\WeatherState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WeatherController extends AbstractSimulatorController
{
    private const int STALE_THRESHOLD_SECONDS = 3600;

    #[Route('/weather', methods: ['GET'])]
    public function getWeather(WeatherState $weather): JsonResponse
    {
        $current = $weather->current();
        $lastUpdatedAt = $weather->lastUpdatedAt();
        $age = null !== $lastUpdatedAt ? time() - $lastUpdatedAt->getTimestamp() : null;

        // WeatherResponse declares no nullable property, so an empty state omits the keys
        // it has no value for rather than sending null.
        return new JsonResponse(array_filter(
            [
                'valid' => null !== $current,
                'age' => $age,
                'stale' => null !== $age && $age > self::STALE_THRESHOLD_SECONDS,
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

        $weather->update($current, $forecast, self::asStringKeyedArray($body['today'] ?? null));

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
