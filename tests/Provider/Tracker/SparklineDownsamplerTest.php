<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\SparklineDownsampler;
use PHPUnit\Framework\TestCase;

final class SparklineDownsamplerTest extends TestCase
{
    public function testFewerPointsThanTheMaximumAreReturnedUnchanged(): void
    {
        $points = [1.0, 2.0, 3.0];

        self::assertSame($points, SparklineDownsampler::downsampleToAtMost($points, 24));
    }

    public function testExactlyTheMaximumIsReturnedUnchanged(): void
    {
        $points = self::buildSeries(24);

        self::assertSame($points, SparklineDownsampler::downsampleToAtMost($points, 24));
    }

    public function testMoreThanTheMaximumIsDownsampledToExactlyTheMaximumKeepingFirstAndLastPoint(): void
    {
        $points = self::buildSeries(168);

        $downsampledPoints = SparklineDownsampler::downsampleToAtMost($points, 24);

        self::assertCount(24, $downsampledPoints);
        self::assertSame($points[0], $downsampledPoints[0]);
        self::assertSame($points[167], $downsampledPoints[23]);
    }

    public function testMaxPointCountOfOneReturnsOnlyTheLastPoint(): void
    {
        $points = self::buildSeries(168);

        self::assertSame([$points[167]], SparklineDownsampler::downsampleToAtMost($points, 1));
    }

    public function testEmptyListReturnsAnEmptyList(): void
    {
        self::assertSame([], SparklineDownsampler::downsampleToAtMost([], 24));
    }

    /**
     * @return list<float>
     */
    private static function buildSeries(int $pointCount): array
    {
        $points = [];
        for ($pointIndex = 0; $pointIndex < $pointCount; ++$pointIndex) {
            $points[] = (float) $pointIndex;
        }

        return $points;
    }
}
