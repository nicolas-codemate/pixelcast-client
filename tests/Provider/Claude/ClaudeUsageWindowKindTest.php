<?php

declare(strict_types=1);

namespace App\Tests\Provider\Claude;

use App\Provider\Claude\ClaudeUsageWindow;
use App\Provider\Claude\ClaudeUsageWindowKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClaudeUsageWindowKindTest extends TestCase
{
    /**
     * @return iterable<string, array{ClaudeUsageWindowKind, string, int}>
     */
    public static function provideWindowKindCases(): iterable
    {
        yield 'the rolling five hours' => [ClaudeUsageWindowKind::Session, 'session', 18000];
        yield 'the rolling seven days' => [ClaudeUsageWindowKind::WeeklyAll, 'weekly_all', 604800];
        yield 'the model-scoped seven days' => [ClaudeUsageWindowKind::WeeklyScoped, 'weekly_scoped', 604800];
    }

    #[DataProvider('provideWindowKindCases')]
    public function testEveryKindCarriesItsWireValueAndItsWindowLength(
        ClaudeUsageWindowKind $kind,
        string $expectedWireValue,
        int $expectedSecondsInWindow,
    ): void {
        self::assertSame($expectedWireValue, $kind->value);
        self::assertSame($expectedSecondsInWindow, $kind->secondsInWindow());
        self::assertSame($expectedSecondsInWindow, ClaudeUsageWindow::create($kind, 41, null)->secondsInWindow());
    }
}
