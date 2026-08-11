<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

use App\Client\Tracker\TrackerPayload;

/**
 * The volume series drawn behind the price curve of a tracker.
 */
final class TradedVolumeSeries
{
    /**
     * The downsampler picks points by index, so two series of the same length come out on the
     * same indices and stay aligned on the time axis. A source serving more volumes than prices
     * keeps its most recent ones, both series ending in the present; one serving fewer is
     * dropped rather than stretched over days it does not cover.
     *
     * @param list<float> $pricePoints the price series, before downsampling
     * @param list<float> $tradedVolumes the volumes of the same period, oldest first
     *
     * @return list<float> empty when there is nothing to draw under the curve
     */
    public static function alignedWith(array $pricePoints, array $tradedVolumes): array
    {
        $pricePointCount = \count($pricePoints);
        if (0 === $pricePointCount || \count($tradedVolumes) < $pricePointCount) {
            return [];
        }

        $tradedVolumes = \array_slice($tradedVolumes, \count($tradedVolumes) - $pricePointCount);

        // Indices and forex quotes come with a volume of zero on every bar, which draws nothing.
        if ([] === array_filter($tradedVolumes, static fn (float $tradedVolume): bool => $tradedVolume > 0.0)) {
            return [];
        }

        return SparklineDownsampler::downsampleToAtMost($tradedVolumes, TrackerPayload::MAXIMUM_SPARKLINE_POINTS);
    }
}
