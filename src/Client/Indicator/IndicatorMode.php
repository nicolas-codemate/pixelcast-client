<?php

declare(strict_types=1);

namespace App\Client\Indicator;

enum IndicatorMode: string
{
    case Solid = 'solid';
    case Blink = 'blink';
    case Fade = 'fade';
    case Off = 'off';

    public function lightsTheIndicator(): bool
    {
        return self::Off !== $this;
    }
}
