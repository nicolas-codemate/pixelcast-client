<?php

declare(strict_types=1);

namespace App\Tui;

final class TerminalSafeText
{
    public static function stripControlBytes(string $value): string
    {
        return preg_replace("/[\x00-\x08\x0b-\x1f\x7f]|\xc2[\x80-\x9f]/", '', $value) ?? '';
    }
}
