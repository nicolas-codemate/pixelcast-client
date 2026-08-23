<?php

declare(strict_types=1);

namespace App\Tests\Simulator\State;

use App\Simulator\State\IndicatorState;
use PHPUnit\Framework\TestCase;

final class IndicatorStateTest extends TestCase
{
    public function testALitPushWithoutAColorTakesTheColorTheSlotKeeps(): void
    {
        $state = new IndicatorState();
        $state->set(3, ['mode' => 'solid', 'color' => '#00FF00']);

        $state->set(3, ['mode' => 'blink', 'blinkInterval' => 1000]);

        self::assertSame(['mode' => 'blink', 'blinkInterval' => 1000, 'color' => '#00FF00'], $state->get(3));
    }

    public function testEachSlotKeepsItsOwnColor(): void
    {
        $state = new IndicatorState();
        $state->set(1, ['mode' => 'solid', 'color' => '#FF0000']);
        $state->set(3, ['mode' => 'solid', 'color' => '#00FF00']);

        $state->set(1, ['mode' => 'solid']);
        $state->set(3, ['mode' => 'solid']);

        self::assertSame(['mode' => 'solid', 'color' => '#FF0000'], $state->get(1));
        self::assertSame(['mode' => 'solid', 'color' => '#00FF00'], $state->get(3));
    }

    public function testAnOffPushTakesNoColorFromTheSlot(): void
    {
        $state = new IndicatorState();
        $state->set(2, ['mode' => 'solid', 'color' => '#0000FF']);

        $state->set(2, ['mode' => 'off']);

        self::assertSame(['mode' => 'off'], $state->get(2));
    }

    public function testAColorPushedAsRgbComponentsIsNotReplacedByTheKeptOne(): void
    {
        $state = new IndicatorState();
        $state->set(1, ['mode' => 'solid', 'color' => '#FF0000']);

        $state->set(1, ['mode' => 'solid', 'color' => [0, 0, 255]]);

        self::assertSame(['mode' => 'solid', 'color' => [0, 0, 255]], $state->get(1));
    }

    public function testTheKeptColorSurvivesAnExportAndARestore(): void
    {
        $exportingState = new IndicatorState();
        $exportingState->set(3, ['mode' => 'solid', 'color' => '#00FF00']);

        $restoredState = new IndicatorState();
        $restoredState->restoreFromPersistence($exportingState->exportForPersistence());
        $restoredState->set(3, ['mode' => 'solid']);

        self::assertSame(['mode' => 'solid', 'color' => '#00FF00'], $restoredState->get(3));
    }

    public function testAStateFileWrittenWithoutKeptColorsIsStillReadable(): void
    {
        $state = new IndicatorState();

        $state->restoreFromPersistence(['slots' => [3 => ['mode' => 'solid', 'color' => '#00FF00']]]);

        self::assertSame(['mode' => 'solid', 'color' => '#00FF00'], $state->get(3));
    }
}
