<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class BrightnessState implements ResettableState
{
    private const int DEFAULT_BRIGHTNESS = 128;
    private const int MIN_BRIGHTNESS = 0;
    private const int MAX_BRIGHTNESS = 255;

    private int $brightness = self::DEFAULT_BRIGHTNESS;

    public function set(int $value): void
    {
        $this->brightness = max(self::MIN_BRIGHTNESS, min(self::MAX_BRIGHTNESS, $value));
    }

    public function current(): int
    {
        return $this->brightness;
    }

    public function reset(): void
    {
        $this->brightness = self::DEFAULT_BRIGHTNESS;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return ['brightness' => $this->brightness];
    }

    public function domainKey(): string
    {
        return 'brightness';
    }
}
