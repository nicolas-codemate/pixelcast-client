<?php

declare(strict_types=1);

namespace App\Tests\Tui\Inspector;

use App\Tui\Inspector\InspectorSnapshot;
use App\Tui\Inspector\RequestLogPanel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class RequestLogPanelTest extends TestCase
{
    public function testWidgetReturnsContainerWithHeaderAndBody(): void
    {
        $panel = new RequestLogPanel();

        self::assertInstanceOf(ContainerWidget::class, $panel->widget());
        self::assertSame('Request Log', $panel->headerText());
        self::assertSame('No data', $panel->bodyText());
    }

    public function testUpdateWithNullSnapshotShowsNoData(): void
    {
        $panel = new RequestLogPanel();

        $panel->update(null, busy: false);

        self::assertSame('No data', $panel->bodyText());
        self::assertSame('Request Log', $panel->headerText());
    }

    public function testUpdateWithUnreachableSnapshotShowsUnreachable(): void
    {
        $panel = new RequestLogPanel();

        $panel->update(InspectorSnapshot::unreachable('connection failed'), busy: false);

        self::assertSame('Unreachable', $panel->bodyText());
    }

    public function testUpdateWithReachableSnapshotDelegatesToFormatter(): void
    {
        $panel = new RequestLogPanel();
        $snapshot = InspectorSnapshot::fromInspectPayload([
            'state' => [],
            'requests' => [
                [
                    'timestamp' => '2026-05-25T10:00:00+00:00',
                    'method' => 'POST',
                    'path' => '/api/v2/notifications',
                    'validation' => ['valid' => true],
                ],
            ],
        ]);

        $panel->update($snapshot, busy: false);

        self::assertStringContainsString('POST', $panel->bodyText());
        self::assertStringContainsString('/api/v2/notifications', $panel->bodyText());
        self::assertStringContainsString('OK', $panel->bodyText());
    }

    public function testUpdateWithBusyTrueAppendsPollingHintToHeader(): void
    {
        $panel = new RequestLogPanel();

        $panel->update(null, busy: true);

        self::assertSame('Request Log  polling...', $panel->headerText());
    }
}
