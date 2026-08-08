<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\StaleBehavior;

/**
 * How long the device keeps showing the apps of a sync group once the pushes stop, and what it
 * then does with them. The firmware cannot know when the next push is due, so the client declares it.
 */
final readonly class StaleDeclaration
{
    public const int MAXIMUM_STALE_AFTER_IN_SECONDS = 604800;

    private const string STALE_AFTER_OPTION_KEY = 'staleAfter';
    private const string STALE_BEHAVIOR_OPTION_KEY = 'staleBehavior';
    private const int MINIMUM_STALE_AFTER_IN_SECONDS = 0;

    private function __construct(
        public int $staleAfterInSeconds,
        public ?StaleBehavior $staleBehavior,
    ) {
    }

    /**
     * @param array<string, mixed> $options the options of the sync group carrying the declaration
     * @param list<StaleBehavior> $acceptedBehaviors the behaviours this sync group accepts
     */
    public static function fromOptions(array $options, string $parentPath, SyncInterval $interval, array $acceptedBehaviors): self
    {
        $declaredStaleAfterInSeconds = SyncOptionReader::optionalInt(
            $options,
            self::STALE_AFTER_OPTION_KEY,
            $parentPath,
            minimum: self::MINIMUM_STALE_AFTER_IN_SECONDS,
            maximum: self::MAXIMUM_STALE_AFTER_IN_SECONDS,
        );

        // An interval of three days would derive 777600 seconds, more than the device accepts.
        $staleAfterInSeconds = min(
            $declaredStaleAfterInSeconds ?? $interval->toleratedSilenceInSeconds(),
            self::MAXIMUM_STALE_AFTER_IN_SECONDS,
        );

        return new self(
            $staleAfterInSeconds,
            SyncOptionReader::optionalEnum($options, self::STALE_BEHAVIOR_OPTION_KEY, $parentPath, $acceptedBehaviors),
        );
    }
}
