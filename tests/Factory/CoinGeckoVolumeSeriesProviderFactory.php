<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Provider\Tracker\CoinGeckoVolumeSeriesProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Serves a rising volume series long enough for any tracker fixture: the client keeps the most
 * recent points of it, so a test knows the last volume it sends whatever the length of the curve.
 */
final class CoinGeckoVolumeSeriesProviderFactory
{
    public const int VOLUME_POINT_COUNT = 169;

    private const float VOLUME_STEP = 1000000.0;
    private const string COINGECKO_BASE_URI = 'https://api.coingecko.com/api/v3/';

    public static function withFixtureVolumes(?LoggerInterface $logger = null): CoinGeckoVolumeSeriesProvider
    {
        return self::withHttpClient(new MockHttpClient(self::respondWithVolumeSeries(...), self::COINGECKO_BASE_URI), $logger);
    }

    public static function withHttpClient(MockHttpClient $httpClient, ?LoggerInterface $logger = null): CoinGeckoVolumeSeriesProvider
    {
        return new CoinGeckoVolumeSeriesProvider(
            $httpClient,
            new ArrayAdapter(),
            $logger ?? new NullLogger(),
        );
    }

    public static function respondWithVolumeSeries(): MockResponse
    {
        $volumePoints = [];
        for ($pointIndex = 0; $pointIndex < self::VOLUME_POINT_COUNT; ++$pointIndex) {
            $volumePoints[] = [$pointIndex, ($pointIndex + 1) * self::VOLUME_STEP];
        }

        return new MockResponse(json_encode(['total_volumes' => $volumePoints], \JSON_THROW_ON_ERROR));
    }

    public static function newestVolume(): float
    {
        return self::VOLUME_POINT_COUNT * self::VOLUME_STEP;
    }

    public static function volumeAtIndex(int $pointIndex): float
    {
        return ($pointIndex + 1) * self::VOLUME_STEP;
    }
}
