<?php

declare(strict_types=1);

namespace App\Tests\Tui\SyncNow\Panel;

use App\Tui\SyncNow\Panel\SyncNowPanel;
use App\Tui\SyncNow\SyncNowResult;
use App\Tui\SyncNow\SyncTarget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class SyncNowPanelTest extends TestCase
{
    private SyncNowPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panel = new SyncNowPanel();
    }

    public function testWidgetIsContainerAndInitialResultIsEmpty(): void
    {
        self::assertInstanceOf(ContainerWidget::class, $this->panel->widget());
        self::assertInstanceOf(SelectListWidget::class, $this->panel->selectListWidget());
        self::assertSame('', $this->panel->currentResultText());
    }

    public function testInitialListShowsFirstTargetLabelWithLastNever(): void
    {
        $selected = $this->panel->selectListWidget()->getSelectedItem();

        self::assertNotNull($selected);
        self::assertSame('sync-weather', $selected['value']);
        self::assertStringContainsString('SyncWeatherMessage', $selected['label']);
        self::assertStringContainsString('last: never', $selected['label']);
    }

    public function testInitialLastDispatchLabelForEveryTargetIsNever(): void
    {
        foreach (SyncTarget::cases() as $target) {
            self::assertSame('never', $this->panel->lastDispatchLabelFor($target));
        }
    }

    public function testRecordDispatchStoresFormattedTimestampForTargetOnly(): void
    {
        $dispatchedAt = new \DateTimeImmutable('2026-05-25 14:32:07');

        $this->panel->recordDispatch(SyncTarget::Weather, $dispatchedAt);

        self::assertSame('14:32:07', $this->panel->lastDispatchLabelFor(SyncTarget::Weather));
        self::assertSame('never', $this->panel->lastDispatchLabelFor(SyncTarget::Tracker));
    }

    public function testRecordDispatchPreservesSelectedTarget(): void
    {
        $this->panel->selectListWidget()->setSelectedIndex(1);
        $trackerBeforeDispatch = $this->panel->selectListWidget()->getSelectedItem();
        self::assertNotNull($trackerBeforeDispatch);
        self::assertSame('sync-tracker', $trackerBeforeDispatch['value']);

        $this->panel->recordDispatch(SyncTarget::Tracker, new \DateTimeImmutable('2026-05-25 14:32:07'));

        $selectedAfterDispatch = $this->panel->selectListWidget()->getSelectedItem();
        self::assertNotNull($selectedAfterDispatch);
        self::assertSame('sync-tracker', $selectedAfterDispatch['value']);
    }

    public function testShowResultFormatsDispatched(): void
    {
        $this->panel->showResult(SyncNowResult::dispatched('App\\Message\\SyncWeatherMessage'));

        self::assertSame('OK App\\Message\\SyncWeatherMessage dispatched', $this->panel->currentResultText());
    }

    public function testShowResultFormatsNotWired(): void
    {
        $this->panel->showResult(SyncNowResult::notWired('App\\Message\\SyncWeatherMessage'));

        self::assertSame(
            'NOT WIRED App\\Message\\SyncWeatherMessage (class does not exist)',
            $this->panel->currentResultText(),
        );
    }

    public function testShowResultFormatsDispatchError(): void
    {
        $this->panel->showResult(SyncNowResult::dispatchError('bus down'));

        self::assertSame('FAIL: bus down', $this->panel->currentResultText());
    }

    public function testShowResultStripsControlBytes(): void
    {
        $this->panel->showResult(SyncNowResult::dispatchError("bus\x1b[2J down"));

        self::assertSame('FAIL: bus[2J down', $this->panel->currentResultText());
    }

    public function testClearResultEmptiesTheResultLine(): void
    {
        $this->panel->showResult(SyncNowResult::dispatched('App\\Message\\SyncWeatherMessage'));

        $this->panel->clearResult();

        self::assertSame('', $this->panel->currentResultText());
    }
}
