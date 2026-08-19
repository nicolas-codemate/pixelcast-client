<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\Color;
use App\Client\StaleBehavior;
use App\Message\SyncGitHubMessage;
use App\Message\SyncMessage;

/**
 * The pull requests waiting for a review, counted by one search query and pushed as one custom
 * app. The group carries no items, which puts its shape next to the Claude group.
 *
 * No credential lives here either. The token is read from PIXELCAST_GITHUB_TOKEN, like every
 * other secret of this application.
 */
final readonly class GitHubSyncConfig implements SyncGroupConfig
{
    private const string DEFAULT_ICON_NAME = 'github';

    /**
     * A tint that stays out of the green, yellow and red the count itself is drawn in, so that the
     * label under the number is never read as a level.
     */
    private const string DEFAULT_LABEL_COLOR_HEX_CODE = '#7C9CB0';

    public function __construct(
        public bool $enabled,
        public SyncInterval $interval,
        public StaleDeclaration $staleDeclaration,
        public ?ActiveWindow $activeWindow,
        public string $query,
        public string $label,
        public string $iconName,
        public Color $labelColor,
    ) {
    }

    public static function syncType(): string
    {
        return 'github';
    }

    public static function fromOptions(array $options): self
    {
        $optionsPath = 'syncs.'.self::syncType();

        $interval = SyncInterval::fromOptions($options, $optionsPath);

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            // Two behaviours only, like the weather group: the custom app layout draws neither `dim` nor `badge`.
            staleDeclaration: StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::ACCEPTED_OUTSIDE_TRACKER_AND_GAUGE),
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath),
            query: SyncOptionReader::requireString($options, 'query', $optionsPath),
            label: SyncOptionReader::requireString($options, 'label', $optionsPath),
            iconName: SyncOptionReader::optionalString($options, 'icon', $optionsPath) ?? self::DEFAULT_ICON_NAME,
            labelColor: SyncOptionReader::optionalColor($options, 'color', $optionsPath) ?? Color::fromHexCode(self::DEFAULT_LABEL_COLOR_HEX_CODE),
        );
    }

    public function syncMessage(): SyncMessage
    {
        return new SyncGitHubMessage();
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
