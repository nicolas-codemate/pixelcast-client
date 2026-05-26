<?php

declare(strict_types=1);

namespace App\Tests\Tui\StatusBar;

use App\Tui\StatusBar\StatusBarWidget;
use App\Tui\TuiMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class StatusBarWidgetTest extends TestCase
{
    public function testWidgetReturnsHorizontalContainerWithChipAndRestLine(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Dev);

        $widget = $statusBar->widget();

        self::assertInstanceOf(ContainerWidget::class, $widget);

        $style = $widget->getStyle();
        self::assertNotNull($style);
        self::assertSame(Direction::Horizontal, $style->getDirection());
        self::assertSame(1, $style->getGap());

        $children = $widget->all();
        self::assertCount(2, $children);
        self::assertInstanceOf(TextWidget::class, $children[0]);
        self::assertInstanceOf(TextWidget::class, $children[1]);
    }

    public function testDevModeChipTextAndBoldStyle(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Dev);

        self::assertSame(' DEV ', $statusBar->chipText());

        $chipStyle = $statusBar->chipStyle();
        self::assertNotNull($chipStyle->getBackground());
        self::assertNotNull($chipStyle->getColor());
        self::assertTrue($chipStyle->getBold());
    }

    public function testProdModeChipTextAndBoldStyle(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Prod);

        self::assertSame(' PROD ', $statusBar->chipText());

        $chipStyle = $statusBar->chipStyle();
        self::assertNotNull($chipStyle->getBackground());
        self::assertNotNull($chipStyle->getColor());
        self::assertTrue($chipStyle->getBold());
    }

    public function testDevAndProdChipsHaveDistinctColors(): void
    {
        $devStyle = new StatusBarWidget(TuiMode::Dev)->chipStyle();
        $prodStyle = new StatusBarWidget(TuiMode::Prod)->chipStyle();

        $devBackground = $devStyle->getBackground();
        $prodBackground = $prodStyle->getBackground();
        self::assertNotNull($devBackground);
        self::assertNotNull($prodBackground);

        self::assertNotSame($devBackground->toHex(), $prodBackground->toHex());
    }

    public function testSetBaseLineUpdatesRestLineWithoutModeLabel(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Dev);
        $statusBar->setBaseLine('TARGET: http://device.local (online)   [Q] quit');

        $restLine = $statusBar->restLineText();
        self::assertStringContainsString('TARGET:', $restLine);
        self::assertStringContainsString('[Q] quit', $restLine);
        self::assertStringNotContainsString('MODE:', $restLine);
    }

    public function testSetUnsavedChangesAppendsIndicatorToRestLine(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Prod);
        $statusBar->setBaseLine('TARGET: http://device.local (online)   [Q] quit');
        $statusBar->setUnsavedChanges(true);

        self::assertStringContainsString('UNSAVED CHANGES', $statusBar->restLineText());
    }

    public function testClearingUnsavedChangesRemovesIndicator(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Prod);
        $statusBar->setBaseLine('TARGET: http://device.local (online)   [Q] quit');
        $statusBar->setUnsavedChanges(true);
        $statusBar->setUnsavedChanges(false);

        self::assertStringNotContainsString('UNSAVED CHANGES', $statusBar->restLineText());
    }
}
