<?php

declare(strict_types=1);

namespace App\Tui\Menu;

use App\Tui\TuiMode;

final class TuiMenuFactory
{
    /**
     * @return list<TuiMenuItem>
     */
    public static function buildForMode(TuiMode $mode): array
    {
        $sharedScenariosItem = new TuiMenuItem('1', 'Scenarios', 'scenarios');

        if (TuiMode::Dev === $mode) {
            return [
                $sharedScenariosItem,
                new TuiMenuItem('2', 'Sync Now', 'sync-now'),
                new TuiMenuItem('3', 'Reset Sim', 'reset-sim'),
            ];
        }

        return [
            $sharedScenariosItem,
            new TuiMenuItem('2', 'Configuration', 'configuration'),
            new TuiMenuItem('3', 'Device Status', 'device-status'),
        ];
    }

    /**
     * @param list<TuiMenuItem> $menuItems
     *
     * @return list<array{value: string, label: string}>
     */
    public static function toSelectListItems(array $menuItems): array
    {
        $selectListItems = [];
        foreach ($menuItems as $menuItem) {
            $selectListItems[] = [
                'value' => $menuItem->value,
                'label' => \sprintf('[%s] %s', $menuItem->shortcut, $menuItem->label),
            ];
        }

        return $selectListItems;
    }
}
