<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard\Renderer;

use App\Tui\Dashboard\Renderer\IndicatorsRenderer;
use App\Tui\DeviceState\DeviceDomainState;
use PHPUnit\Framework\TestCase;

final class IndicatorsRendererTest extends TestCase
{
    public function testHasDataFalseReturnsNoData(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testAllSlotsNullRendersDashPerSlot(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(true, [
            'slot1' => null,
            'slot2' => null,
            'slot3' => null,
        ]);

        $output = $renderer->render($state);

        self::assertSame("slot1: -\nslot2: -\nslot3: -", $output);
    }

    public function testOneSlotSetRendersLabelAndDashesForOthers(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(true, [
            'slot1' => null,
            'slot2' => ['label' => 'alarm'],
            'slot3' => null,
        ]);

        $output = $renderer->render($state);

        self::assertSame("slot1: -\nslot2: alarm\nslot3: -", $output);
    }

    public function testStringSlotIsRenderedDirectly(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(true, [
            'slot1' => 'battery',
            'slot2' => null,
            'slot3' => null,
        ]);

        $output = $renderer->render($state);

        self::assertSame("slot1: battery\nslot2: -\nslot3: -", $output);
    }

    public function testControlBytesInPayloadAreStrippedFromOutput(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(true, [
            'slot1' => "bat\x1bXtery",
            'slot2' => ['label' => "ala\x1bXrm"],
            'slot3' => null,
        ]);

        $output = $renderer->render($state);

        self::assertStringNotContainsString("\x1b", $output);
    }

    public function testSummaryReturnsEmptyStringWhenStateHasNoData(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('', $renderer->summary($state));
    }

    public function testSummaryReturnsExpectedTextWithFullPayload(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(true, [
            'slot1' => 'battery',
            'slot2' => ['label' => 'alarm'],
            'slot3' => null,
        ]);

        self::assertSame('2/3 slots', $renderer->summary($state));
    }

    public function testSummaryHandlesPartialOrEmptyPayloadGracefully(): void
    {
        $renderer = new IndicatorsRenderer();
        $state = new DeviceDomainState(true, []);

        self::assertSame('0/3 slots', $renderer->summary($state));
    }
}
