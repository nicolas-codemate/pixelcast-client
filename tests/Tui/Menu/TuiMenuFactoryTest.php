<?php

declare(strict_types=1);

namespace App\Tests\Tui\Menu;

use App\Tui\Menu\TuiMenuFactory;
use App\Tui\Menu\TuiMenuItem;
use App\Tui\TuiMode;
use PHPUnit\Framework\TestCase;

final class TuiMenuFactoryTest extends TestCase
{
    public function testBuildForDevModeProducesThreeItemsWithExpectedLabels(): void
    {
        $items = TuiMenuFactory::buildForMode(TuiMode::Dev);

        self::assertCount(3, $items);
        self::assertSame(
            ['1', '2', '3'],
            array_map(static fn (TuiMenuItem $item): string => $item->shortcut, $items),
        );
        self::assertSame(
            ['Scenarios', 'Sync Now', 'Reset Sim'],
            array_map(static fn (TuiMenuItem $item): string => $item->label, $items),
        );
        self::assertSame(
            ['scenarios', 'sync-now', 'reset-sim'],
            array_map(static fn (TuiMenuItem $item): string => $item->value, $items),
        );
    }

    public function testBuildForProdModeProducesThreeItemsWithExpectedLabels(): void
    {
        $items = TuiMenuFactory::buildForMode(TuiMode::Prod);

        self::assertCount(3, $items);
        self::assertSame(
            ['1', '2', '3'],
            array_map(static fn (TuiMenuItem $item): string => $item->shortcut, $items),
        );
        self::assertSame(
            ['Scenarios', 'Configuration', 'Device Status'],
            array_map(static fn (TuiMenuItem $item): string => $item->label, $items),
        );
        self::assertSame(
            ['scenarios', 'configuration', 'device-status'],
            array_map(static fn (TuiMenuItem $item): string => $item->value, $items),
        );
    }

    public function testToSelectListItemsFormatsLabelWithShortcut(): void
    {
        $items = TuiMenuFactory::buildForMode(TuiMode::Dev);

        $selectListItems = TuiMenuFactory::toSelectListItems($items);

        self::assertSame(
            [
                ['value' => 'scenarios', 'label' => '[1] Scenarios'],
                ['value' => 'sync-now', 'label' => '[2] Sync Now'],
                ['value' => 'reset-sim', 'label' => '[3] Reset Sim'],
            ],
            $selectListItems,
        );
    }
}
