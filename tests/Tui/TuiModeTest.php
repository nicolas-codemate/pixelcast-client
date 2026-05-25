<?php

declare(strict_types=1);

namespace App\Tests\Tui;

use App\Tui\TuiMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TuiModeTest extends TestCase
{
    public function testFromAppEnvironmentReturnsDevForLiteralDevValue(): void
    {
        self::assertSame(TuiMode::Dev, TuiMode::fromAppEnvironment('dev'));
    }

    #[DataProvider('nonDevEnvironmentProvider')]
    public function testFromAppEnvironmentReturnsProdForNonDevValues(string $appEnvironment): void
    {
        self::assertSame(TuiMode::Prod, TuiMode::fromAppEnvironment($appEnvironment));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonDevEnvironmentProvider(): iterable
    {
        yield 'literal prod' => ['prod'];
        yield 'literal test' => ['test'];
        yield 'literal staging' => ['staging'];
        yield 'empty string' => [''];
        yield 'uppercase DEV (case-sensitive)' => ['DEV'];
        yield 'longer development word' => ['development'];
    }

    public function testDisplayLabel(): void
    {
        self::assertSame('DEV', TuiMode::Dev->displayLabel());
        self::assertSame('PROD', TuiMode::Prod->displayLabel());
    }
}
