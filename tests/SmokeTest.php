<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SmokeTest extends KernelTestCase
{
    public function testKernelBootsAndContainerCompiles(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        self::assertTrue($container->has('kernel'));
    }
}
