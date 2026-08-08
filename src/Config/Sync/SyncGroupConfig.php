<?php

declare(strict_types=1);

namespace App\Config\Sync;

interface SyncGroupConfig
{
    public bool $enabled { get; }

    public SyncInterval $interval { get; }

    public StaleDeclaration $staleDeclaration { get; }

    public ?ActiveWindow $activeWindow { get; }

    /**
     * The key under `syncs:` in pixelcast.yaml, and the value accepted by `app:sync <type>`.
     */
    public static function syncType(): string;

    /**
     * @param array<string, mixed> $options options already validated against pixelcast.schema.json
     */
    public static function fromOptions(array $options): self;

    public function syncMessage(): object;

    /**
     * Whether the group has anything to push at this instant, and since when it has had.
     */
    public function activityAt(\DateTimeImmutable $instant): SyncGroupActivity;
}
