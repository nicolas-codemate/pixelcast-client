<?php

declare(strict_types=1);

namespace App\Config\Sync;

interface SyncGroupConfig
{
    public bool $enabled { get; }

    public SyncInterval $interval { get; }

    public StaleDeclaration $staleDeclaration { get; }

    /**
     * The key under `syncs:` in pixelcast.yaml, and the value accepted by `app:sync <type>`.
     */
    public static function syncType(): string;

    /**
     * @param array<string, mixed> $options options already validated against pixelcast.schema.json
     */
    public static function fromOptions(array $options): self;

    public function syncMessage(): object;
}
