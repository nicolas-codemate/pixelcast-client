<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\GitHubSyncConfig;
use App\Message\SyncGitHubMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubSyncConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validOptions(): array
    {
        return [
            'enabled' => true,
            'interval' => '5 minutes',
            'query' => 'is:open is:pr review-requested:@me',
            'label' => 'A relire',
        ];
    }

    public function testTheSyncTypeIsTheKeyUsedInTheConfigurationFile(): void
    {
        self::assertSame('github', GitHubSyncConfig::syncType());
    }

    public function testAValidOptionMapIsHydrated(): void
    {
        $gitHubSync = GitHubSyncConfig::fromOptions(self::validOptions());

        self::assertTrue($gitHubSync->enabled);
        self::assertSame('5 minutes', $gitHubSync->interval->expression);
        self::assertSame('is:open is:pr review-requested:@me', $gitHubSync->query);
        self::assertSame('A relire', $gitHubSync->label);
    }

    public function testWithoutAnIconAndAColourTheGroupDefaultsApply(): void
    {
        $gitHubSync = GitHubSyncConfig::fromOptions(self::validOptions());

        self::assertSame('github', $gitHubSync->iconName);
        self::assertSame('#8957E5', $gitHubSync->color->hexCode);
    }

    public function testADeclaredColourIsNormalisedToItsUppercaseHexCode(): void
    {
        $gitHubSync = GitHubSyncConfig::fromOptions(array_merge(self::validOptions(), ['color' => '#8957e5']));

        self::assertSame('#8957E5', $gitHubSync->color->hexCode);
    }

    public function testAColourThatIsNotAHexCodeNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.github.color');

        GitHubSyncConfig::fromOptions(array_merge(self::validOptions(), ['color' => 'purple']));
    }

    public function testAMissingQueryNamesItsFullPath(): void
    {
        $options = self::validOptions();
        unset($options['query']);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.github.query');

        GitHubSyncConfig::fromOptions($options);
    }

    public function testAnEmptyLabelNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.github.label');

        GitHubSyncConfig::fromOptions(array_merge(self::validOptions(), ['label' => '   ']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBehavioursOnlyTheTrackerAndGaugeLayoutsDraw(): iterable
    {
        yield 'dim' => ['dim'];
        yield 'badge' => ['badge'];
    }

    #[DataProvider('provideBehavioursOnlyTheTrackerAndGaugeLayoutsDraw')]
    public function testTheBehavioursOnlyTheTrackerAndGaugeLayoutsDrawAreRefused(string $refusedBehavior): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.github.staleBehavior');

        GitHubSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => $refusedBehavior]));
    }

    public function testHideAndNoneAreAccepted(): void
    {
        $hidden = GitHubSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'hide']));
        $unsigned = GitHubSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'none']));

        self::assertSame(StaleBehavior::Hide, $hidden->staleDeclaration->staleBehavior);
        self::assertSame(StaleBehavior::None, $unsigned->staleDeclaration->staleBehavior);
    }

    public function testWithoutFreshnessKeysTheSilenceToleratedIsThreeIntervals(): void
    {
        $gitHubSync = GitHubSyncConfig::fromOptions(self::validOptions());

        self::assertSame(900, $gitHubSync->staleDeclaration->staleAfterInSeconds);
        self::assertNull($gitHubSync->staleDeclaration->staleBehavior);
    }

    public function testTheGroupIsTriggeredByAGitHubSyncMessage(): void
    {
        self::assertInstanceOf(SyncGitHubMessage::class, GitHubSyncConfig::fromOptions(self::validOptions())->syncMessage());
    }

    public function testAnActiveWindowSaysWhetherTheGroupIsDispatchableAndSinceWhen(): void
    {
        $gitHubSync = GitHubSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'activeWindow' => ['days' => ['mon'], 'from' => '09:00', 'to' => '19:00', 'timezone' => 'Europe/Paris'],
        ]));

        $insideTheWindow = $gitHubSync->activityAt(new \DateTimeImmutable('2026-08-03 17:00:00', new \DateTimeZone('Europe/Paris')));
        self::assertTrue($insideTheWindow->isActive);
        self::assertSame(28800, $insideTheWindow->secondsSinceBecameActive);

        $outsideTheWindow = $gitHubSync->activityAt(new \DateTimeImmutable('2026-08-03 20:00:00', new \DateTimeZone('Europe/Paris')));
        self::assertFalse($outsideTheWindow->isActive);
        self::assertNull($outsideTheWindow->secondsSinceBecameActive);
    }
}
