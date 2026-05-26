<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\AppDomain;
use PHPUnit\Framework\TestCase;

final class AppDomainTest extends TestCase
{
    public function testEnumExposesSixAppDomainCases(): void
    {
        $expected = [
            'Weather' => 'weather',
            'Trackers' => 'trackers',
            'Notifications' => 'notifications',
            'CustomApps' => 'customApps',
            'Indicators' => 'indicators',
            'Icons' => 'icons',
        ];

        $actual = [];
        foreach (AppDomain::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame($expected, $actual);
    }
}
