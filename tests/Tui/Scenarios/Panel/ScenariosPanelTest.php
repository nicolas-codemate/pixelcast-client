<?php

declare(strict_types=1);

namespace App\Tests\Tui\Scenarios\Panel;

use App\Tui\Scenarios\Panel\ScenariosPanel;
use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioResult;
use App\Tui\TuiMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class ScenariosPanelTest extends TestCase
{
    private ScenariosPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panel = new ScenariosPanel(new ScenarioCatalog(), TuiMode::Dev);
    }

    public function testWidgetIsContainerAndInitialResultIsEmpty(): void
    {
        self::assertInstanceOf(ContainerWidget::class, $this->panel->widget());
        self::assertInstanceOf(SelectListWidget::class, $this->panel->selectListWidget());
        self::assertSame('', $this->panel->currentResultText());
    }

    public function testSelectListIsPrePopulatedWithCatalogEntries(): void
    {
        $selected = $this->panel->selectListWidget()->getSelectedItem();

        self::assertNotNull($selected);
        self::assertSame('weather', $selected['value']);
    }

    public function testShowResultFormatsSuccessWithStatusOnlyWhenMessageIsDefault(): void
    {
        $this->panel->showResult(ScenarioResult::success(204));

        self::assertSame('OK 204', $this->panel->currentResultText());
    }

    public function testShowResultFormatsSuccessWithMessageAppendedWhenInformative(): void
    {
        $this->panel->showResult(ScenarioResult::success(201, 'created'));

        self::assertSame('OK 201: created', $this->panel->currentResultText());
    }

    public function testShowResultFormatsValidationFailure(): void
    {
        $this->panel->showResult(ScenarioResult::validationFailure('text is required'));

        self::assertSame('VALIDATION text is required', $this->panel->currentResultText());
    }

    public function testShowResultFormatsTransportFailureWithHttpStatus(): void
    {
        $this->panel->showResult(ScenarioResult::transportFailure('server error', 500));

        self::assertSame('FAIL HTTP 500: server error', $this->panel->currentResultText());
    }

    public function testShowResultFormatsTransportFailureWithoutHttpStatus(): void
    {
        $this->panel->showResult(ScenarioResult::transportFailure('Transport error: boom'));

        self::assertSame('FAIL Transport error: boom', $this->panel->currentResultText());
    }

    public function testShowResultFormatsUnreachable(): void
    {
        $this->panel->showResult(ScenarioResult::unreachable('connection refused'));

        self::assertSame('UNREACHABLE connection refused', $this->panel->currentResultText());
    }

    public function testShowResultStripsControlBytesFromMessage(): void
    {
        $this->panel->showResult(ScenarioResult::unreachable("conn\x1b[2J refused"));

        self::assertSame('UNREACHABLE conn[2J refused', $this->panel->currentResultText());
    }

    public function testClearResultEmptiesTheResultLine(): void
    {
        $this->panel->showResult(ScenarioResult::success(200));

        $this->panel->clearResult();

        self::assertSame('', $this->panel->currentResultText());
    }
}
