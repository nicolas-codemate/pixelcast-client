<?php

declare(strict_types=1);

namespace App\Client\Icon;

/**
 * What GET /icons reports: the icons stored on the device and the room left for more.
 */
final readonly class IconsSnapshot
{
    /**
     * @param list<IconInfo> $icons
     */
    private function __construct(
        public array $icons,
        public int $count,
        public ?IconStorage $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    public static function fromResponseBody(array $decodedBody): self
    {
        $icons = self::readIcons($decodedBody['icons'] ?? null);
        $count = $decodedBody['count'] ?? null;

        return new self(
            icons: $icons,
            count: \is_int($count) ? $count : \count($icons),
            storage: self::readStorage($decodedBody['storage'] ?? null),
        );
    }

    public function hasIcon(string $name): bool
    {
        foreach ($this->icons as $icon) {
            if ($icon->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function iconNames(): array
    {
        return array_map(static fn (IconInfo $icon): string => $icon->name, $this->icons);
    }

    /**
     * @return list<IconInfo>
     */
    private static function readIcons(mixed $icons): array
    {
        if (!\is_array($icons)) {
            return [];
        }

        $readIcons = [];

        foreach ($icons as $icon) {
            if (!\is_array($icon)) {
                continue;
            }

            $name = $icon['name'] ?? null;
            $fileName = $icon['filename'] ?? null;
            $sizeInBytes = $icon['size'] ?? null;

            if (!\is_string($name) || '' === $name) {
                continue;
            }

            $readIcons[] = new IconInfo(
                $name,
                \is_string($fileName) ? $fileName : '',
                \is_int($sizeInBytes) ? $sizeInBytes : 0,
            );
        }

        return $readIcons;
    }

    private static function readStorage(mixed $storage): ?IconStorage
    {
        if (!\is_array($storage)) {
            return null;
        }

        $usedBytes = $storage['used'] ?? null;
        $totalBytes = $storage['total'] ?? null;

        if (!\is_int($usedBytes) || !\is_int($totalBytes)) {
            return null;
        }

        return new IconStorage($usedBytes, $totalBytes);
    }
}
