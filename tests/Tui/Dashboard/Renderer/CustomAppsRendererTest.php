<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard\Renderer;

use App\Tui\Dashboard\Renderer\CustomAppsRenderer;
use App\Tui\DeviceState\DeviceDomainState;
use PHPUnit\Framework\TestCase;

final class CustomAppsRendererTest extends TestCase
{
    public function testHasDataFalseReturnsNoData(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testEmptyAppsArrayReturnsNoData(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(true, ['apps' => []]);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testListOfStringsRendersCountAndNames(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(true, [
            'apps' => ['stocks', 'agenda'],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('Count: 2', $output);
        self::assertStringContainsString('- stocks', $output);
        self::assertStringContainsString('- agenda', $output);
    }

    public function testListOfObjectsRendersCountAndNames(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(true, [
            'apps' => [
                ['name' => 'stocks'],
                ['name' => 'agenda'],
                ['name' => 'sport'],
            ],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('Count: 3', $output);
        self::assertStringContainsString('- stocks', $output);
        self::assertStringContainsString('- agenda', $output);
        self::assertStringContainsString('- sport', $output);
    }

    public function testNameListIsTruncatedToFive(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(true, [
            'apps' => ['a1', 'a2', 'a3', 'a4', 'a5', 'a6'],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('Count: 6', $output);
        self::assertStringContainsString('- a5', $output);
        self::assertStringNotContainsString('- a6', $output);
    }

    public function testControlBytesInPayloadAreStrippedFromOutput(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(true, [
            'apps' => ["sto\x1bXcks", ['name' => "age\x1bXnda"]],
        ]);

        $output = $renderer->render($state);

        self::assertStringNotContainsString("\x1b", $output);
    }

    public function testSummaryReturnsEmptyStringWhenStateHasNoData(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('', $renderer->summary($state));
    }

    public function testSummaryReturnsExpectedTextWithFullPayload(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(true, [
            'apps' => ['stocks', 'agenda'],
        ]);

        self::assertSame('2 apps', $renderer->summary($state));
    }

    public function testSummaryHandlesPartialOrEmptyPayloadGracefully(): void
    {
        $renderer = new CustomAppsRenderer();
        $state = new DeviceDomainState(true, ['apps' => []]);

        self::assertSame('', $renderer->summary($state));
    }
}
