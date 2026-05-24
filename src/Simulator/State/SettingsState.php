<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class SettingsState implements ResettableState
{
    private const array DEFAULT_SETTINGS = [
        'brightness' => 128,
        'autoRotate' => true,
        'defaultDuration' => 10000,
    ];

    /** @var array<string, mixed> */
    private array $settings = self::DEFAULT_SETTINGS;

    /**
     * @param array<string, mixed> $partial
     */
    public function patch(array $partial): void
    {
        $this->settings = array_replace($this->settings, $partial);
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return $this->settings;
    }

    public function reset(): void
    {
        $this->settings = self::DEFAULT_SETTINGS;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->settings;
    }

    public function domainKey(): string
    {
        return 'settings';
    }
}
