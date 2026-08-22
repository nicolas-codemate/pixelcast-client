<?php

declare(strict_types=1);

namespace App\Message;

/**
 * The tick asking the client to hold the panel at the level of the brightness window covering the
 * current instant.
 *
 * Deliberately not a SyncMessage: brightness has no provider and no freshness to watch, so it has
 * no place in app:sync nor in the healthcheck.
 */
final readonly class ApplyBrightnessMessage
{
}
