<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\ClaudeSyncConfig;
use App\Config\Sync\ClaudeUsageRowLabel;
use App\Message\SyncClaudeMessage;
use PHPUnit\Framework\TestCase;

final class ClaudeSyncConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validOptions(): array
    {
        return [
            'enabled' => true,
            'interval' => '15 minutes',
        ];
    }

    public function testTheSyncTypeIsTheKeyUsedInTheConfigurationFile(): void
    {
        self::assertSame('claude', ClaudeSyncConfig::syncType());
    }

    public function testAValidOptionMapIsHydrated(): void
    {
        $claudeSync = ClaudeSyncConfig::fromOptions(self::validOptions());

        self::assertTrue($claudeSync->enabled);
        self::assertSame('15 minutes', $claudeSync->interval->expression);
    }

    public function testWithoutFreshnessKeysTheSilenceToleratedIsThreeIntervalsAndTheBehaviourIsLeftToTheFirmware(): void
    {
        $claudeSync = ClaudeSyncConfig::fromOptions(self::validOptions());

        self::assertSame(2700, $claudeSync->staleDeclaration->staleAfterInSeconds);
        self::assertNull($claudeSync->staleDeclaration->staleBehavior);
    }

    public function testTheGaugeLayoutBehavioursTheWeatherGroupRefusesAreAcceptedHere(): void
    {
        $dimmed = ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'dim']));
        $badged = ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'badge']));

        self::assertSame(StaleBehavior::Dim, $dimmed->staleDeclaration->staleBehavior);
        self::assertSame(StaleBehavior::Badge, $badged->staleDeclaration->staleBehavior);
    }

    public function testAnUnknownStaleBehaviourNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.claude.staleBehavior');

        ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleBehavior' => 'blink']));
    }

    public function testADeclaredSilenceOverridesTheOneDerivedFromTheInterval(): void
    {
        $claudeSync = ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), ['staleAfter' => 900]));

        self::assertSame(900, $claudeSync->staleDeclaration->staleAfterInSeconds);
    }

    public function testAMissingEnabledFlagNamesItsFullPath(): void
    {
        $options = self::validOptions();
        unset($options['enabled']);

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.claude.enabled');

        ClaudeSyncConfig::fromOptions($options);
    }

    public function testAnIntervalShorterThanAMinuteNamesTheOption(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.claude.interval');

        ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), ['interval' => '30 seconds']));
    }

    public function testTheGroupIsTriggeredByAClaudeSyncMessage(): void
    {
        self::assertInstanceOf(SyncClaudeMessage::class, ClaudeSyncConfig::fromOptions(self::validOptions())->syncMessage());
    }

    public function testWithoutAnActiveWindowTheGroupHasAlwaysBeenDispatchable(): void
    {
        $activity = ClaudeSyncConfig::fromOptions(self::validOptions())->activityAt(new \DateTimeImmutable('2026-08-08 03:00:00'));

        self::assertTrue($activity->isActive);
        self::assertNull($activity->secondsSinceBecameActive);
    }

    public function testAnActiveWindowSaysWhetherTheGroupIsDispatchableAndSinceWhen(): void
    {
        $claudeSync = ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'activeWindow' => ['days' => ['mon'], 'from' => '08:00', 'to' => '18:00', 'timezone' => 'Europe/Paris'],
        ]));

        $insideTheWindow = $claudeSync->activityAt(new \DateTimeImmutable('2026-08-03 16:00:00', new \DateTimeZone('Europe/Paris')));
        self::assertTrue($insideTheWindow->isActive);
        self::assertSame(28800, $insideTheWindow->secondsSinceBecameActive);

        $outsideTheWindow = $claudeSync->activityAt(new \DateTimeImmutable('2026-08-03 19:00:00', new \DateTimeZone('Europe/Paris')));
        self::assertFalse($outsideTheWindow->isActive);
        self::assertNull($outsideTheWindow->secondsSinceBecameActive);
    }

    public function testWithoutHiddenRowsNoRowIsHidden(): void
    {
        $claudeSync = ClaudeSyncConfig::fromOptions(self::validOptions());

        self::assertSame([], $claudeSync->hiddenRows);
    }

    public function testHiddenRowsParsesTheDeclaredLabelsIntoTheirEnumCases(): void
    {
        $claudeSync = ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'hiddenRows' => ['credits', 'fable'],
        ]));

        self::assertSame([ClaudeUsageRowLabel::Credits, ClaudeUsageRowLabel::Fable], $claudeSync->hiddenRows);
    }

    public function testAnExplicitlyEmptyHiddenRowsListIsAccepted(): void
    {
        $claudeSync = ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'hiddenRows' => [],
        ]));

        self::assertSame([], $claudeSync->hiddenRows);
    }

    public function testAnUnknownHiddenRowNamesItsFullPathWithIndex(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.claude.hiddenRows[1]');

        ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'hiddenRows' => ['credits', 'unknown'],
        ]));
    }

    public function testAHiddenRowsValueThatIsNotAListNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.claude.hiddenRows');

        ClaudeSyncConfig::fromOptions(array_merge(self::validOptions(), [
            'hiddenRows' => 'credits',
        ]));
    }
}
