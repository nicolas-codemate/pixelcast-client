<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard;

use App\Tui\Dashboard\CollapsibleBlock;
use App\Tui\Style\Palette;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\Color;

final class CollapsibleBlockTest extends TestCase
{
    public function testInitialStateIsCollapsedAndOffWithHiddenBody(): void
    {
        $block = new CollapsibleBlock('Weather');

        self::assertFalse($block->isExpanded());
        self::assertSame('  Weather [off]', $block->headerText());
        self::assertSame('no data', $block->bodyText());

        $bodyStyle = $block->bodyContainer()->getStyle();
        self::assertNotNull($bodyStyle);
        self::assertTrue($bodyStyle->getHidden());
    }

    public function testExpandRemovesHiddenFlagAndFlipsExpandedFlag(): void
    {
        $block = new CollapsibleBlock('Weather');

        $block->expand();

        self::assertTrue($block->isExpanded());
        $bodyStyle = $block->bodyContainer()->getStyle();
        self::assertNotNull($bodyStyle);
        self::assertFalse($bodyStyle->getHidden());
    }

    public function testCollapseRestoresHiddenFlag(): void
    {
        $block = new CollapsibleBlock('Weather');
        $block->expand();

        $block->collapse();

        self::assertFalse($block->isExpanded());
        $bodyStyle = $block->bodyContainer()->getStyle();
        self::assertNotNull($bodyStyle);
        self::assertTrue($bodyStyle->getHidden());
    }

    public function testToggleRoundTripsBetweenExpandedAndCollapsed(): void
    {
        $block = new CollapsibleBlock('Weather');

        $block->toggle();
        self::assertTrue($block->isExpanded());

        $block->toggle();
        self::assertFalse($block->isExpanded());

        $block->toggle();
        self::assertTrue($block->isExpanded());
    }

    public function testSetStateWithHasDataTrueWritesOnMarkerAndBodyText(): void
    {
        $block = new CollapsibleBlock('Weather');

        $block->setState(true, 'foo');

        self::assertSame('  Weather [on]', $block->headerText());
        self::assertSame('foo', $block->bodyText());
    }

    public function testSetStateWithHasDataFalseWritesOffMarkerAndNoDataBody(): void
    {
        $block = new CollapsibleBlock('Weather');
        $block->setState(true, 'foo');

        $block->setState(false, 'no data');

        self::assertSame('  Weather [off]', $block->headerText());
        self::assertSame('no data', $block->bodyText());
    }

    public function testSetSelectedTruePrependsSelectionArrowOnHeader(): void
    {
        $block = new CollapsibleBlock('Weather');

        $block->setSelected(true);

        self::assertSame('> Weather [off]', $block->headerText());
    }

    public function testSetSelectedFalseRestoresUnselectedPrefix(): void
    {
        $block = new CollapsibleBlock('Weather');
        $block->setSelected(true);

        $block->setSelected(false);

        self::assertSame('  Weather [off]', $block->headerText());
    }

    public function testHeaderReflectsBothSelectionAndStateMarker(): void
    {
        $block = new CollapsibleBlock('Trackers');

        $block->setSelected(true);
        $block->setState(true, 'body');

        self::assertSame('> Trackers [on]', $block->headerText());
    }

    public function testHeaderIncludesSummaryWhileCollapsed(): void
    {
        $block = new CollapsibleBlock('Icons');

        $block->setSummary('5 icons');

        self::assertStringEndsWith('5 icons', $block->headerText());
    }

    public function testHeaderHidesSummaryWhenExpanded(): void
    {
        $block = new CollapsibleBlock('Icons');
        $block->setSummary('5 icons');

        $block->expand();

        self::assertStringNotContainsString('5 icons', $block->headerText());
    }

    public function testHeaderWidgetIsBoldWhenHasDataIsTrue(): void
    {
        $palette = new Palette();
        $block = new CollapsibleBlock('Weather');

        $block->setState(true, 'body');

        $headerStyle = $block->headerStyle();
        self::assertNotNull($headerStyle);
        self::assertTrue($headerStyle->getBold());
        $color = $headerStyle->getColor();
        self::assertNotNull($color);
        self::assertSame(Color::from($palette->headerText)->toHex(), $color->toHex());
    }

    public function testHeaderWidgetIsDimWhenHasDataIsFalse(): void
    {
        $palette = new Palette();
        $block = new CollapsibleBlock('Weather');

        $block->setState(false, 'no data');

        $headerStyle = $block->headerStyle();
        self::assertNotNull($headerStyle);
        self::assertTrue($headerStyle->getDim());
        $color = $headerStyle->getColor();
        self::assertNotNull($color);
        self::assertSame(Color::from($palette->dimText)->toHex(), $color->toHex());
    }

    public function testOuterContainerBorderUsesAccentColorWhenOn(): void
    {
        $palette = new Palette();
        $block = new CollapsibleBlock('Weather');

        $block->setState(true, 'body');

        $border = $block->widget()->getStyle()?->getBorder();
        self::assertNotNull($border);
        $borderColor = $border->color;
        self::assertNotNull($borderColor);
        self::assertSame(Color::from($palette->borderAccent)->toHex(), $borderColor->toHex());
    }

    public function testOuterContainerBorderUsesDimColorWhenOff(): void
    {
        $palette = new Palette();
        $block = new CollapsibleBlock('Weather');

        $border = $block->widget()->getStyle()?->getBorder();
        self::assertNotNull($border);
        $borderColor = $border->color;
        self::assertNotNull($borderColor);
        self::assertSame(Color::from($palette->borderDim)->toHex(), $borderColor->toHex());
    }

    public function testSelectedBlockUsesFullBorderInsteadOfTopOnly(): void
    {
        $block = new CollapsibleBlock('Weather');
        $block->setState(true, 'body');

        $unselectedBorder = $block->widget()->getStyle()?->getBorder();
        self::assertNotNull($unselectedBorder);
        self::assertSame(1, $unselectedBorder->top);
        self::assertSame(0, $unselectedBorder->right);
        self::assertSame(0, $unselectedBorder->bottom);
        self::assertSame(0, $unselectedBorder->left);

        $block->setSelected(true);

        $selectedBorder = $block->widget()->getStyle()?->getBorder();
        self::assertNotNull($selectedBorder);
        self::assertSame(1, $selectedBorder->top);
        self::assertSame(1, $selectedBorder->right);
        self::assertSame(1, $selectedBorder->bottom);
        self::assertSame(1, $selectedBorder->left);
    }
}
