<?php

declare(strict_types=1);

namespace App\Simulator\State;

final class IconState implements ResettableState
{
    private const int DEFAULT_SIZE_BYTES = 256;
    private const string DEFAULT_TYPE = 'png';

    /** @var array<string, array<string, mixed>> */
    private array $icons = [];

    public function register(string $name, ?int $size = null, ?string $type = null): void
    {
        $this->icons[$name] = [
            'name' => $name,
            'size' => $size ?? self::DEFAULT_SIZE_BYTES,
            'type' => $type ?? self::DEFAULT_TYPE,
        ];
    }

    public function delete(string $name): bool
    {
        if (!\array_key_exists($name, $this->icons)) {
            return false;
        }

        unset($this->icons[$name]);

        return true;
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->icons);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_values($this->icons);
    }

    public function count(): int
    {
        return \count($this->icons);
    }

    public function reset(): void
    {
        $this->icons = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'icons' => array_values($this->icons),
            'count' => \count($this->icons),
        ];
    }

    public function domainKey(): string
    {
        return 'icons';
    }
}
