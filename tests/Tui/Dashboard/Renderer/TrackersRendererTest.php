<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard\Renderer;

use App\Tui\Dashboard\Renderer\TrackersRenderer;
use App\Tui\DeviceState\DeviceDomainState;
use PHPUnit\Framework\TestCase;

final class TrackersRendererTest extends TestCase
{
    public function testHasDataFalseReturnsNoData(): void
    {
        $renderer = new TrackersRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testEmptyTrackersArrayReturnsNoData(): void
    {
        $renderer = new TrackersRenderer();
        $state = new DeviceDomainState(true, ['trackers' => []]);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testSingleTrackerRendersLabelValueRow(): void
    {
        $renderer = new TrackersRenderer();
        $state = new DeviceDomainState(true, [
            'trackers' => [
                ['label' => 'BTC', 'value' => '42000'],
            ],
        ]);

        self::assertSame('BTC: 42000', $renderer->render($state));
    }

    public function testMultipleTrackersAreRenderedOnSeparateLines(): void
    {
        $renderer = new TrackersRenderer();
        $state = new DeviceDomainState(true, [
            'trackers' => [
                ['label' => 'BTC', 'value' => '42000'],
                ['label' => 'ETH', 'value' => '3000'],
                ['label' => 'DOGE', 'value' => '0.15'],
            ],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('BTC: 42000', $output);
        self::assertStringContainsString('ETH: 3000', $output);
        self::assertStringContainsString('DOGE: 0.15', $output);
        self::assertSame(3, substr_count($output, "\n") + 1);
    }

    public function testTrackerListIsTruncatedToEightRows(): void
    {
        $renderer = new TrackersRenderer();
        $trackers = [];
        for ($index = 1; $index <= 12; ++$index) {
            $trackers[] = ['label' => 'T'.$index, 'value' => (string) $index];
        }
        $state = new DeviceDomainState(true, ['trackers' => $trackers]);

        $output = $renderer->render($state);

        self::assertStringContainsString('T1: 1', $output);
        self::assertStringContainsString('T8: 8', $output);
        self::assertStringNotContainsString('T9: 9', $output);
    }

    public function testControlBytesInPayloadAreStrippedFromOutput(): void
    {
        $renderer = new TrackersRenderer();
        $state = new DeviceDomainState(true, [
            'trackers' => [
                ['label' => "BT\x1bXC", 'value' => "42\x1bX000"],
            ],
        ]);

        $output = $renderer->render($state);

        self::assertStringNotContainsString("\x1b", $output);
    }
}
