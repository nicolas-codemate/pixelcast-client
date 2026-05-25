<?php

declare(strict_types=1);

namespace App\Tests\Tui\Inspector;

use App\Tui\Inspector\InspectorSnapshot;
use App\Tui\Inspector\StateInspectorPanel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class StateInspectorPanelTest extends TestCase
{
    public function testWidgetReturnsContainerWithHeaderAndBody(): void
    {
        $panel = new StateInspectorPanel();

        self::assertInstanceOf(ContainerWidget::class, $panel->widget());
        self::assertSame('State Inspector', $panel->headerText());
        self::assertSame('No data', $panel->bodyText());
    }

    public function testUpdateWithNullSnapshotShowsNoData(): void
    {
        $panel = new StateInspectorPanel();

        $panel->update(null, busy: false);

        self::assertSame('No data', $panel->bodyText());
        self::assertSame('State Inspector', $panel->headerText());
    }

    public function testUpdateWithUnreachableSnapshotShowsUnreachable(): void
    {
        $panel = new StateInspectorPanel();

        $panel->update(InspectorSnapshot::unreachable('connection failed'), busy: false);

        self::assertSame('Unreachable', $panel->bodyText());
    }

    public function testUpdateWithReachableSnapshotDelegatesToFormatter(): void
    {
        $panel = new StateInspectorPanel();
        $snapshot = InspectorSnapshot::fromInspectPayload([
            'state' => ['weather' => ['city' => 'Paris']],
            'requests' => [],
        ]);

        $panel->update($snapshot, busy: false);

        self::assertStringStartsWith('[weather]', $panel->bodyText());
    }

    public function testUpdateWithBusyTrueAppendsPollingHintToHeader(): void
    {
        $panel = new StateInspectorPanel();

        $panel->update(null, busy: true);

        self::assertSame('State Inspector  polling...', $panel->headerText());
    }
}
