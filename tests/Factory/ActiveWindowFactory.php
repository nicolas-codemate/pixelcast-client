<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Config\Sync\ActiveWindow;
use PHPUnit\Framework\Assert;

/**
 * The window the tests judge instants against: the opening hours of a European stock market.
 */
final class ActiveWindowFactory
{
    private const string PARENT_PATH = 'syncs.boursorama';

    public static function marketWindowIn(string $timezoneName): ActiveWindow
    {
        $activeWindow = ActiveWindow::optionalFromOptions([
            'activeWindow' => [
                'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'from' => '09:00',
                'to' => '17:45',
                'timezone' => $timezoneName,
            ],
        ], self::PARENT_PATH);
        Assert::assertNotNull($activeWindow);

        return $activeWindow;
    }
}
