<?php

declare(strict_types=1);

namespace App\Tests\Tui\Overlay;

use App\Tui\Overlay\OverlayMenu;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\TextWidget;

final class OverlayMenuTest extends TestCase
{
    /**
     * @var list<array{value: string, label: string}>
     */
    private const array SAMPLE_ITEMS = [
        ['value' => 'scenarios', 'label' => '[1] Scenarios'],
        ['value' => 'sync-now', 'label' => '[2] Sync Now'],
    ];

    public function testIsHiddenOnConstruction(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        $style = $overlay->widget()->getStyle();
        self::assertNotNull($style);
        self::assertTrue($style->getHidden());
    }

    public function testSelectListIsPopulatedWithConstructorItems(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        $selected = $overlay->selectListWidget()->getSelectedItem();
        self::assertNotNull($selected);
        self::assertSame('scenarios', $selected['value']);
        self::assertSame('[1] Scenarios', $selected['label']);
    }

    public function testShowRevealsTheOverlay(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        $overlay->show();

        $style = $overlay->widget()->getStyle();
        self::assertNotNull($style);
        self::assertFalse($style->getHidden());
    }

    public function testHideConcealsTheOverlay(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        $overlay->show();
        $overlay->hide();

        $style = $overlay->widget()->getStyle();
        self::assertNotNull($style);
        self::assertTrue($style->getHidden());
    }

    public function testTitleAndFooterAreRenderedInsideTheOverlay(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS);

        $children = $overlay->widget()->all();
        self::assertGreaterThanOrEqual(3, \count($children));

        $first = $children[0];
        self::assertInstanceOf(TextWidget::class, $first);
        self::assertSame('Menu', $first->getText());

        $last = $children[\count($children) - 1];
        self::assertInstanceOf(TextWidget::class, $last);
        self::assertStringContainsString('[Esc] close', $last->getText());
    }

    public function testCustomTitleIsRespected(): void
    {
        $overlay = new OverlayMenu(self::SAMPLE_ITEMS, 'Actions');

        $children = $overlay->widget()->all();
        $first = $children[0];
        self::assertInstanceOf(TextWidget::class, $first);
        self::assertSame('Actions', $first->getText());
    }
}
