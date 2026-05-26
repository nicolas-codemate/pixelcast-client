<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard;

use App\Tui\Dashboard\CollapsibleBlock;
use PHPUnit\Framework\TestCase;

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
}
