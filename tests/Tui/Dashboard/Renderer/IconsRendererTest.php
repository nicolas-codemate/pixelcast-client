<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard\Renderer;

use App\Tui\Dashboard\Renderer\IconsRenderer;
use App\Tui\DeviceState\DeviceDomainState;
use PHPUnit\Framework\TestCase;

final class IconsRendererTest extends TestCase
{
    public function testHasDataFalseReturnsNoData(): void
    {
        $renderer = new IconsRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testEmptyIconsArrayReturnsNoData(): void
    {
        $renderer = new IconsRenderer();
        $state = new DeviceDomainState(true, ['icons' => []]);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testListOfStringsRendersCountAndNames(): void
    {
        $renderer = new IconsRenderer();
        $state = new DeviceDomainState(true, [
            'icons' => ['weather', 'clock', 'mail'],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('Count: 3', $output);
        self::assertStringContainsString('- weather', $output);
        self::assertStringContainsString('- clock', $output);
        self::assertStringContainsString('- mail', $output);
    }

    public function testListOfObjectsRendersCountAndNames(): void
    {
        $renderer = new IconsRenderer();
        $state = new DeviceDomainState(true, [
            'icons' => [
                ['name' => 'weather'],
                ['name' => 'clock'],
            ],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('Count: 2', $output);
        self::assertStringContainsString('- weather', $output);
        self::assertStringContainsString('- clock', $output);
    }

    public function testNameListIsTruncatedToFive(): void
    {
        $renderer = new IconsRenderer();
        $state = new DeviceDomainState(true, [
            'icons' => ['i1', 'i2', 'i3', 'i4', 'i5', 'i6', 'i7'],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('Count: 7', $output);
        self::assertStringContainsString('- i1', $output);
        self::assertStringContainsString('- i5', $output);
        self::assertStringNotContainsString('- i6', $output);
        self::assertStringNotContainsString('- i7', $output);
    }

    public function testControlBytesInPayloadAreStrippedFromOutput(): void
    {
        $renderer = new IconsRenderer();
        $state = new DeviceDomainState(true, [
            'icons' => ["wea\x1bXther", ['name' => "clo\x1bXck"]],
        ]);

        $output = $renderer->render($state);

        self::assertStringNotContainsString("\x1b", $output);
    }
}
