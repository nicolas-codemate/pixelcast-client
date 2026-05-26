<?php

declare(strict_types=1);

namespace App\Tests\Tui\StatusBar;

use App\Tui\StatusBar\StatusBarWidget;
use App\Tui\Style\Palette;
use App\Tui\TuiMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\Color;
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

    public function testDevModeChipTextAndPaletteColors(): void
    {
        $palette = new Palette();
        $statusBar = new StatusBarWidget(TuiMode::Dev, $palette);

        self::assertSame(' DEV ', $statusBar->chipText());

        $chipStyle = $statusBar->chipStyle();
        $background = $chipStyle->getBackground();
        $foreground = $chipStyle->getColor();
        self::assertNotNull($background);
        self::assertNotNull($foreground);
        self::assertSame(Color::from($palette->devChipBackground)->toHex(), $background->toHex());
        self::assertSame(Color::from($palette->devChipForeground)->toHex(), $foreground->toHex());
        self::assertTrue($chipStyle->getBold());
    }

    public function testProdModeChipTextAndPaletteColors(): void
    {
        $palette = new Palette();
        $statusBar = new StatusBarWidget(TuiMode::Prod, $palette);

        self::assertSame(' PROD ', $statusBar->chipText());

        $chipStyle = $statusBar->chipStyle();
        $background = $chipStyle->getBackground();
        $foreground = $chipStyle->getColor();
        self::assertNotNull($background);
        self::assertNotNull($foreground);
        self::assertSame(Color::from($palette->prodChipBackground)->toHex(), $background->toHex());
        self::assertSame(Color::from($palette->prodChipForeground)->toHex(), $foreground->toHex());
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

    public function testStatusBarUsesDefaultPaletteWhenNoneProvided(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Dev);
        $defaults = new Palette();

        $background = $statusBar->chipStyle()->getBackground();
        self::assertNotNull($background);
        self::assertSame(Color::from($defaults->devChipBackground)->toHex(), $background->toHex());
    }

    public function testSetBaseLineUpdatesRestLineWithoutModeLabel(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Dev);
        $statusBar->setBaseLine('TARGET: http://device.local (online) │ [Q] quit');

        $restLine = $statusBar->restLineText();
        self::assertStringContainsString('TARGET:', $restLine);
        self::assertStringContainsString('[Q] quit', $restLine);
        self::assertStringNotContainsString('MODE:', $restLine);
    }

    public function testSetUnsavedChangesAppendsIndicatorToRestLineWithBoxDrawingSeparator(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Prod);
        $statusBar->setBaseLine('TARGET: http://device.local (online) │ [Q] quit');
        $statusBar->setUnsavedChanges(true);

        $restLine = $statusBar->restLineText();
        self::assertStringContainsString('UNSAVED CHANGES', $restLine);
        self::assertStringContainsString(' │ UNSAVED CHANGES', $restLine);
        self::assertStringNotContainsString('   UNSAVED CHANGES', $restLine);
    }

    public function testClearingUnsavedChangesRemovesIndicator(): void
    {
        $statusBar = new StatusBarWidget(TuiMode::Prod);
        $statusBar->setBaseLine('TARGET: http://device.local (online) │ [Q] quit');
        $statusBar->setUnsavedChanges(true);
        $statusBar->setUnsavedChanges(false);

        self::assertStringNotContainsString('UNSAVED CHANGES', $statusBar->restLineText());
    }
}
