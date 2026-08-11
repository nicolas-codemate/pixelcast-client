<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
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
    private const string HIDDEN_ROWS_OPTION_KEY = 'hiddenRows';

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

    public static function fromOptions(array $options): self
    {
        $optionsPath = 'syncs.'.self::syncType();

        $interval = SyncInterval::fromOptions($options, $optionsPath);

        return new self(
            enabled: SyncOptionReader::requireBool($options, 'enabled', $optionsPath),
            interval: $interval,
            // All four behaviours, unlike the weather group: the gauge layout draws `dim` and `badge`.
            staleDeclaration: StaleDeclaration::fromOptions($options, $optionsPath, $interval, StaleBehavior::cases()),
            activeWindow: ActiveWindow::optionalFromOptions($options, $optionsPath),
            hiddenRows: self::readHiddenRows($options, $optionsPath),
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

    /**
     * @param array<string, mixed> $options
     *
     * @return list<ClaudeUsageRowLabel>
     */
    private static function readHiddenRows(array $options, string $parentPath): array
    {
        if (!SyncOptionReader::isDeclared($options, self::HIDDEN_ROWS_OPTION_KEY)) {
            return [];
        }

        $hiddenRowsPath = $parentPath.'.'.self::HIDDEN_ROWS_OPTION_KEY;
        $declaredRows = $options[self::HIDDEN_ROWS_OPTION_KEY];
        if (!\is_array($declaredRows) || !array_is_list($declaredRows)) {
            throw PixelCastConfigException::invalidValue($hiddenRowsPath, 'expected a list');
        }

        $hiddenRows = [];
        foreach ($declaredRows as $index => $declaredRow) {
            $row = \is_string($declaredRow) ? ClaudeUsageRowLabel::tryFrom($declaredRow) : null;

            if (null === $row) {
                throw PixelCastConfigException::invalidValue(\sprintf('%s[%d]', $hiddenRowsPath, $index), \sprintf('expected one of: %s', implode(', ', array_column(ClaudeUsageRowLabel::cases(), 'value'))));
            }

            $hiddenRows[] = $row;
        }

        return $hiddenRows;
    }
}
