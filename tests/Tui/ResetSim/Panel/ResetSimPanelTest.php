<?php

declare(strict_types=1);

namespace App\Tests\Tui\ResetSim\Panel;

use App\Tui\ResetSim\Panel\ResetSimPanel;
use App\Tui\Scenarios\ScenarioResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class ResetSimPanelTest extends TestCase
{
    private ResetSimPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panel = new ResetSimPanel();
    }

    public function testWidgetIsContainerAndInitialResultIsEmpty(): void
    {
        self::assertInstanceOf(ContainerWidget::class, $this->panel->widget());
        self::assertInstanceOf(SelectListWidget::class, $this->panel->selectListWidget());
        self::assertSame('', $this->panel->currentResultText());
    }

    public function testInitialPanelExposesSingleSelectableItem(): void
    {
        $selected = $this->panel->selectListWidget()->getSelectedItem();

        self::assertNotNull($selected);
        self::assertSame('reset', $selected['value']);
        self::assertStringContainsString('POST /__reset', $selected['label']);
    }

    public function testShowResultFormatsSuccess(): void
    {
        $this->panel->showResult(ScenarioResult::success(200));

        self::assertSame('OK 200', $this->panel->currentResultText());
    }

    public function testShowResultFormatsTransportFailureWithStatus(): void
    {
        $this->panel->showResult(ScenarioResult::transportFailure('server error', 500));

        self::assertSame('FAIL HTTP 500: server error', $this->panel->currentResultText());
    }

    public function testShowResultFormatsTransportFailureWithoutStatus(): void
    {
        $this->panel->showResult(ScenarioResult::transportFailure('Transport error: boom'));

        self::assertSame('FAIL Transport error: boom', $this->panel->currentResultText());
    }

    public function testShowResultFormatsUnreachable(): void
    {
        $this->panel->showResult(ScenarioResult::unreachable('connection refused'));

        self::assertSame('UNREACHABLE connection refused', $this->panel->currentResultText());
    }

    public function testClearResultEmptiesTheResultLine(): void
    {
        $this->panel->showResult(ScenarioResult::success(200));

        $this->panel->clearResult();

        self::assertSame('', $this->panel->currentResultText());
    }
}
