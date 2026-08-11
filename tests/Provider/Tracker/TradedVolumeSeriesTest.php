<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\SparklineDownsampler;
use App\Provider\Tracker\TradedVolumeSeries;
use PHPUnit\Framework\TestCase;

final class TradedVolumeSeriesTest extends TestCase
{
    public function testAVolumePerPriceIsKeptAsItIs(): void
    {
        $tradedVolumes = [3.2, 4.1, 3.8];

        self::assertSame($tradedVolumes, TradedVolumeSeries::alignedWith([92100.0, 89300.0, 93200.0], $tradedVolumes));
    }

    public function testExtraVolumesAreDroppedFromTheOldestEnd(): void
    {
        $alignedVolumes = TradedVolumeSeries::alignedWith([92100.0, 89300.0], [1.0, 2.0, 3.0, 4.0]);

        self::assertSame([3.0, 4.0], $alignedVolumes);
    }

    public function testFewerVolumesThanPricesLeaveTheChartWithoutBars(): void
    {
        self::assertSame([], TradedVolumeSeries::alignedWith([92100.0, 89300.0, 93200.0], [3.2, 4.1]));
    }

    public function testASeriesOfZerosLeavesTheChartWithoutBars(): void
    {
        self::assertSame([], TradedVolumeSeries::alignedWith([92100.0, 89300.0], [0.0, 0.0]));
    }

    public function testNoVolumeAtAllLeavesTheChartWithoutBars(): void
    {
        self::assertSame([], TradedVolumeSeries::alignedWith([92100.0, 89300.0], []));
    }

    public function testNoPriceAtAllLeavesTheChartWithoutBars(): void
    {
        self::assertSame([], TradedVolumeSeries::alignedWith([], [3.2, 4.1]));
    }

    public function testALongSeriesIsSampledOnTheSameColumnsAsThePriceCurve(): void
    {
        $pricePoints = self::buildSeries(168);
        $tradedVolumes = self::buildSeries(168);

        $alignedVolumes = TradedVolumeSeries::alignedWith($pricePoints, $tradedVolumes);

        self::assertCount(63, $alignedVolumes);
        self::assertSame(SparklineDownsampler::downsampleToAtMost($pricePoints, 63), $alignedVolumes);
    }

    /**
     * @return list<float>
     */
    private static function buildSeries(int $pointCount): array
    {
        $points = [];
        for ($pointIndex = 0; $pointIndex < $pointCount; ++$pointIndex) {
            $points[] = (float) ($pointIndex + 1);
        }

        return $points;
    }
}
