<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

abstract class CommandTestCase extends TestCase
{
    /**
     * SymfonyStyle blocks fold at 120 columns whatever the terminal width, so the COLUMNS pinned in
     * phpunit.dist.xml is not enough on its own: assertions on a message longer than that read the
     * display with its whitespace collapsed.
     */
    protected static function singleLineDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
