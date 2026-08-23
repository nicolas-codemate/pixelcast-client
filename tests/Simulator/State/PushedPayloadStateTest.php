<?php

declare(strict_types=1);

namespace App\Tests\Simulator\State;

use App\Simulator\State\AbstractPushedPayloadState;
use App\Simulator\State\CustomAppState;
use App\Simulator\State\GaugeState;
use App\Simulator\State\TrackerState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushedPayloadStateTest extends TestCase
{
    private const array CLAUDE_PAYLOAD = [
        'title' => 'Claude',
        'icon' => 'claude',
    ];

    /**
     * @return iterable<string, array{class-string<AbstractPushedPayloadState>, string, string, string}>
     */
    public static function provideStatesWithTheirKeys(): iterable
    {
        yield 'trackers' => [TrackerState::class, 'trackers', 'trackersPushedAt', 'trackers'];
        yield 'gauges' => [GaugeState::class, 'gauges', 'gaugesPushedAt', 'gauges'];
        yield 'custom apps' => [CustomAppState::class, 'apps', 'appsPushedAt', 'customApps'];
    }

    /**
     * @param class-string<AbstractPushedPayloadState> $stateClass
     */
    #[DataProvider('provideStatesWithTheirKeys')]
    public function testEachStateKeepsItsOwnSnapshotKeys(
        string $stateClass,
        string $payloadKey,
        string $pushedAtKey,
        string $domainKey,
    ): void {
        $state = new $stateClass();
        $state->upsert('claude', self::CLAUDE_PAYLOAD);

        $snapshot = $state->snapshot();

        self::assertSame([$payloadKey, 'count', $pushedAtKey], array_keys($snapshot));
        self::assertSame(['claude' => self::CLAUDE_PAYLOAD], $snapshot[$payloadKey]);
        self::assertSame(1, $snapshot['count']);
        self::assertSame($domainKey, $state->domainKey());
    }

    /**
     * @param class-string<AbstractPushedPayloadState> $stateClass
     */
    #[DataProvider('provideStatesWithTheirKeys')]
    public function testEachStateReadsBackWhatItExported(string $stateClass, string $payloadKey, string $pushedAtKey): void
    {
        $state = new $stateClass();
        $state->upsert('claude', self::CLAUDE_PAYLOAD);

        $exported = $state->exportForPersistence();
        self::assertSame([$payloadKey, $pushedAtKey], array_keys($exported));

        $restored = new $stateClass();
        $restored->restoreFromPersistence($exported);

        self::assertSame($state->snapshot(), $restored->snapshot());
    }

    /**
     * @param class-string<AbstractPushedPayloadState> $stateClass
     */
    #[DataProvider('provideStatesWithTheirKeys')]
    public function testAStateIgnoresTheSectionOfAnotherState(string $stateClass, string $payloadKey): void
    {
        $state = new $stateClass();

        foreach (array_diff(['trackers', 'gauges', 'apps'], [$payloadKey]) as $foreignKey) {
            $state->restoreFromPersistence([$foreignKey => ['claude' => self::CLAUDE_PAYLOAD]]);

            self::assertSame([], $state->list());
        }
    }
}
