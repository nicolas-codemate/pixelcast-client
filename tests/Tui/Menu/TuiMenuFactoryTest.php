<?php

declare(strict_types=1);

namespace App\Tests\Tui\Menu;

use App\Tui\Menu\TuiMenuFactory;
use App\Tui\Menu\TuiMenuItem;
use App\Tui\TuiMode;
use PHPUnit\Framework\TestCase;

final class TuiMenuFactoryTest extends TestCase
{
    public function testBuildForDevModeProducesFourItemsWithExpectedLabels(): void
    {
        $items = TuiMenuFactory::buildForMode(TuiMode::Dev);

        self::assertCount(4, $items);
        self::assertSame(
            ['1', '2', '3', '4'],
            array_map(static fn (TuiMenuItem $item): string => $item->shortcut, $items),
        );
        self::assertSame(
            ['Scenarios', 'Sync Now', 'Request Log', 'Reset Sim'],
            array_map(static fn (TuiMenuItem $item): string => $item->label, $items),
        );
        self::assertSame(
            ['scenarios', 'sync-now', 'request-log', 'reset-sim'],
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
                ['value' => 'request-log', 'label' => '[3] Request Log'],
                ['value' => 'reset-sim', 'label' => '[4] Reset Sim'],
            ],
            $selectListItems,
        );
    }
}
