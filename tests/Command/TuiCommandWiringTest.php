<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TuiCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TuiCommandWiringTest extends KernelTestCase
{
    public function testTuiCommandResolvesFromContainerWithoutDiErrors(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(TuiCommand::class);

        self::assertInstanceOf(TuiCommand::class, $command);
    }
}
