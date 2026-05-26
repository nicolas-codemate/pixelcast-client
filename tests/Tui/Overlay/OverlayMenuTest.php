<?php

declare(strict_types=1);

namespace App\Tests\Tui\Overlay;

use App\Tui\Overlay\OverlayMenu;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class OverlayMenuTest extends TestCase
{
    /**
     * @var list<array{value: string, label: string}>
     */
    private const array SAMPLE_ITEMS = [
        ['value' => 'scenarios', 'label' => '[1] Scenarios'],
        ['value' => 'sync-now', 'label' => '[2] Sync Now'],
    ];

    public function testInitialStateIsHiddenAndExposesWidgetAndSelectList(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        self::assertInstanceOf(ContainerWidget::class, $overlay->widget());
        self::assertInstanceOf(SelectListWidget::class, $overlay->selectListWidget());

        $style = $overlay->widget()->getStyle();
        self::assertNotNull($style);
        self::assertTrue($style->getHidden());
        self::assertFalse($overlay->isVisible());
    }

    public function testExposesItemsItWasConstructedWith(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        self::assertCount(2, $overlay->items());
        self::assertSame('scenarios', $overlay->items()[0]['value']);
        self::assertSame('[1] Scenarios', $overlay->items()[0]['label']);
        self::assertSame('sync-now', $overlay->items()[1]['value']);
        self::assertSame('[2] Sync Now', $overlay->items()[1]['label']);

        $selected = $overlay->selectListWidget()->getSelectedItem();
        self::assertNotNull($selected);
        self::assertSame('scenarios', $selected['value']);
    }

    public function testShowRevealsAndHideConcealsTheOverlay(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        $overlay->show();
        self::assertTrue($overlay->isVisible());
        $shownStyle = $overlay->widget()->getStyle();
        self::assertNotNull($shownStyle);
        self::assertFalse($shownStyle->getHidden());

        $overlay->hide();
        self::assertFalse($overlay->isVisible());
        $hiddenStyle = $overlay->widget()->getStyle();
        self::assertNotNull($hiddenStyle);
        self::assertTrue($hiddenStyle->getHidden());
    }

    public function testSetItemsReplacesSelectListContents(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        $overlay->setItems([
            ['value' => 'configuration', 'label' => '[1] Configuration'],
        ]);

        self::assertCount(1, $overlay->items());
        self::assertSame('configuration', $overlay->items()[0]['value']);

        $selected = $overlay->selectListWidget()->getSelectedItem();
        self::assertNotNull($selected);
        self::assertSame('configuration', $selected['value']);
        self::assertSame('[1] Configuration', $selected['label']);
    }
}
