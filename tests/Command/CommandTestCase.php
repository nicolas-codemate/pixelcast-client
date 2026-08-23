<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

abstract class CommandTestCase extends TestCase
{
    private string|false $terminalWidthBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();

        // Console blocks wrap at the terminal width, and a width of 80 would fold the messages these
        // assertions read. Pinning it keeps them looking at what the operator is told, not at where
        // the console chose to break a line.
        $this->terminalWidthBeforeTheTest = getenv('COLUMNS');
        putenv('COLUMNS=200');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->terminalWidthBeforeTheTest ? 'COLUMNS' : 'COLUMNS='.$this->terminalWidthBeforeTheTest);

        parent::tearDown();
    }

    /**
     * SymfonyStyle blocks fold at 120 columns whatever the terminal width, so a widened COLUMNS is
     * not enough on its own: assertions on those messages run on a whitespace-collapsed display.
     */
    protected static function singleLineDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
