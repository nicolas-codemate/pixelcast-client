<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard\Renderer;

use App\Tui\Dashboard\Renderer\NotificationsRenderer;
use App\Tui\DeviceState\DeviceDomainState;
use PHPUnit\Framework\TestCase;

final class NotificationsRendererTest extends TestCase
{
    public function testHasDataFalseReturnsNoData(): void
    {
        $renderer = new NotificationsRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testEmptyQueueReturnsNoData(): void
    {
        $renderer = new NotificationsRenderer();
        $state = new DeviceDomainState(true, ['queue' => []]);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testQueueIsRenderedInOrderWithPriorityPrefix(): void
    {
        $renderer = new NotificationsRenderer();
        $state = new DeviceDomainState(true, [
            'queue' => [
                ['priority' => 'high', 'text' => 'first'],
                ['priority' => 'low', 'text' => 'second'],
            ],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('[high] first', $output);
        self::assertStringContainsString('[low] second', $output);
        $highPosition = strpos($output, '[high]');
        $lowPosition = strpos($output, '[low]');
        self::assertNotFalse($highPosition);
        self::assertNotFalse($lowPosition);
        self::assertLessThan($lowPosition, $highPosition);
    }

    public function testFallsBackToNotificationsKeyForProdParity(): void
    {
        $renderer = new NotificationsRenderer();
        $state = new DeviceDomainState(true, [
            'notifications' => [
                ['priority' => 'high', 'text' => 'alert'],
            ],
        ]);

        self::assertSame('[high] alert', $renderer->render($state));
    }

    public function testQueueIsTruncatedToEightRows(): void
    {
        $renderer = new NotificationsRenderer();
        $queue = [];
        for ($index = 1; $index <= 10; ++$index) {
            $queue[] = ['priority' => 'low', 'text' => 'msg'.$index];
        }
        $state = new DeviceDomainState(true, ['queue' => $queue]);

        $output = $renderer->render($state);

        self::assertStringContainsString('msg1', $output);
        self::assertStringContainsString('msg8', $output);
        self::assertStringNotContainsString('msg9', $output);
    }

    public function testControlBytesInPayloadAreStrippedFromOutput(): void
    {
        $renderer = new NotificationsRenderer();
        $state = new DeviceDomainState(true, [
            'queue' => [
                ['priority' => "hi\x1bXgh", 'text' => "ale\x1bXrt"],
            ],
        ]);

        $output = $renderer->render($state);

        self::assertStringNotContainsString("\x1b", $output);
    }
}
