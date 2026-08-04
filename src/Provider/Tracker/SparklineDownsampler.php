<?php

declare(strict_types=1);

namespace App\Provider\Tracker;

final class SparklineDownsampler
{
    /**
     * @param list<float> $points
     *
     * @return list<float>
     */
    public static function downsampleToAtMost(array $points, int $maxPointCount): array
    {
        if ($maxPointCount < 1) {
            return [];
        }

        $pointCount = \count($points);
        if ($pointCount <= $maxPointCount) {
            return $points;
        }

        if (1 === $maxPointCount) {
            return [$points[$pointCount - 1]];
        }

        $stride = ($pointCount - 1) / ($maxPointCount - 1);

        $downsampledPoints = [];
        for ($sampleIndex = 0; $sampleIndex < $maxPointCount; ++$sampleIndex) {
            $downsampledPoints[] = $points[(int) round($sampleIndex * $stride)];
        }

        return $downsampledPoints;
    }
}
