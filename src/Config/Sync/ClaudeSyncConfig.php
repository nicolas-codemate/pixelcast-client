<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\StaleBehavior;
use App\Message\SyncClaudeMessage;
use App\Message\SyncMessage;

/**
 * The Claude subscription counters, pushed as one gauge. The group carries no items: its set of
 * windows is fixed, which puts its shape next to the weather group rather than to the trackers.
 *
 * No credential lives here either. The OAuth pair is read from the file named by
 * PIXELCAST_CLAUDE_CREDENTIALS_FILE, like every other secret of this application.
 */
final readonly class ClaudeSyncConfig implements SyncGroupConfig
{
    /**
     * @param list<ClaudeUsageRowLabel> $hiddenRows
     */
    public function __construct(
        public bool $enabled,
        public SyncInterval $interval,
        public StaleDeclaration $staleDeclaration,
        public ?ActiveWindow $activeWindow,
        public array $hiddenRows,
    ) {
    }

    public static function syncType(): string
    {
        return 'claude';
    }

    public static function fromOptions(array $options, ?\DateTimeZone $deviceTimezone = null): self
    {
        $optionsPath = 'syncs.'.self::syncType();

        $interval = SyncInterval::fromOptions($options, $optionsPath);

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            // All four behaviours, unlike the weather group: the gauge layout draws `dim` and `badge`.
            staleDeclaration: StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::cases()),
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath, $deviceTimezone),
            hiddenRows: SyncOptionReader::optionalEnumList($options, 'hiddenRows', $optionsPath, ClaudeUsageRowLabel::class),
        );
    }

    public function syncMessage(): SyncMessage
    {
        return new SyncClaudeMessage();
    }

    public function activityAt(\DateTimeImmutable $instant): SyncGroupActivity
    {
        $activeWindow = $this->activeWindow;

        if (null !== $activeWindow && !$activeWindow->contains($instant)) {
            return SyncGroupActivity::inactive();
        }

        return SyncGroupActivity::activeSince($activeWindow?->secondsSinceOpening($instant));
    }
}
