<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard\Renderer;

use App\Tui\Dashboard\Renderer\WeatherRenderer;
use App\Tui\DeviceState\DeviceDomainState;
use PHPUnit\Framework\TestCase;

final class WeatherRendererTest extends TestCase
{
    public function testHasDataFalseReturnsNoData(): void
    {
        $renderer = new WeatherRenderer();
        $state = new DeviceDomainState(false, null);

        self::assertSame('no data', $renderer->render($state));
    }

    public function testHasDataTrueButNonArrayPayloadReturnsNoData(): void
    {
        $renderer = new WeatherRenderer();
        $state = new DeviceDomainState(true, 'not an array');

        self::assertSame('no data', $renderer->render($state));
    }

    public function testCurrentBlockRendersTemperatureAndCondition(): void
    {
        $renderer = new WeatherRenderer();
        $state = new DeviceDomainState(true, [
            'current' => ['tempC' => 21, 'condition' => 'partly cloudy'],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('21C', $output);
        self::assertStringContainsString('partly cloudy', $output);
    }

    public function testForecastEntriesAreTruncatedToThree(): void
    {
        $renderer = new WeatherRenderer();
        $state = new DeviceDomainState(true, [
            'current' => ['tempC' => 18, 'condition' => 'sunny'],
            'forecast' => [
                ['day' => 'Mon', 'minC' => 10, 'maxC' => 20, 'condition' => 'sunny'],
                ['day' => 'Tue', 'minC' => 11, 'maxC' => 21, 'condition' => 'clouds'],
                ['day' => 'Wed', 'minC' => 12, 'maxC' => 22, 'condition' => 'rain'],
                ['day' => 'Thu', 'minC' => 13, 'maxC' => 23, 'condition' => 'storm'],
            ],
        ]);

        $output = $renderer->render($state);

        self::assertStringContainsString('Mon', $output);
        self::assertStringContainsString('Tue', $output);
        self::assertStringContainsString('Wed', $output);
        self::assertStringNotContainsString('Thu', $output);
        self::assertStringNotContainsString('storm', $output);
    }

    public function testControlBytesInPayloadAreStrippedFromOutput(): void
    {
        $renderer = new WeatherRenderer();
        $state = new DeviceDomainState(true, [
            'current' => ['tempC' => 21, 'condition' => "partly\x1bX cloudy"],
        ]);

        $output = $renderer->render($state);

        self::assertStringNotContainsString("\x1b", $output);
    }
}
