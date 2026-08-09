<?php

declare(strict_types=1);

namespace App\Tests\Simulator\State;

use App\Simulator\State\GaugeState;
use PHPUnit\Framework\TestCase;

final class GaugeStateTest extends TestCase
{
    private const array CLAUDE_PAYLOAD = [
        'title' => 'Claude',
        'icon' => 'claude',
        'rows' => [
            ['label' => '5h', 'percent' => 41],
            ['label' => '7j', 'percent' => 28],
        ],
    ];

    public function testUpsertedGaugeIsReadableUnderItsName(): void
    {
        $gauges = new GaugeState();

        $gauges->upsert('claude', self::CLAUDE_PAYLOAD);

        self::assertTrue($gauges->has('claude'));
        self::assertSame(self::CLAUDE_PAYLOAD, $gauges->get('claude'));
    }

    public function testAnUnknownGaugeReadsAsNull(): void
    {
        $gauges = new GaugeState();

        self::assertFalse($gauges->has('claude'));
        self::assertNull($gauges->get('claude'));
    }

    public function testUpsertingTwiceUnderTheSameNameReplacesThePayloadWhole(): void
    {
        $gauges = new GaugeState();

        $gauges->upsert('claude', self::CLAUDE_PAYLOAD);
        $gauges->upsert('claude', ['rows' => [['label' => 'credits', 'percent' => 1]]]);

        self::assertSame(['rows' => [['label' => 'credits', 'percent' => 1]]], $gauges->get('claude'));
        self::assertCount(1, $gauges->list());
    }

    public function testDeleteReportsWhetherTheGaugeWasThere(): void
    {
        $gauges = new GaugeState();
        $gauges->upsert('claude', self::CLAUDE_PAYLOAD);

        self::assertTrue($gauges->delete('claude'));
        self::assertFalse($gauges->delete('claude'));
        self::assertSame([], $gauges->list());
    }

    public function testSnapshotCarriesEveryGaugeAndTheirCount(): void
    {
        $gauges = new GaugeState();
        $gauges->upsert('claude', self::CLAUDE_PAYLOAD);
        $gauges->upsert('disks', ['title' => 'Disques']);

        self::assertSame([
            'gauges' => ['claude' => self::CLAUDE_PAYLOAD, 'disks' => ['title' => 'Disques']],
            'count' => 2,
        ], $gauges->snapshot());
    }

    public function testResetEmptiesTheState(): void
    {
        $gauges = new GaugeState();
        $gauges->upsert('claude', self::CLAUDE_PAYLOAD);

        $gauges->reset();

        self::assertSame([], $gauges->list());
        self::assertSame(['gauges' => [], 'count' => 0], $gauges->snapshot());
    }

    public function testExportedStateIsRestoredIntoAFreshInstance(): void
    {
        $saved = new GaugeState();
        $saved->upsert('claude', self::CLAUDE_PAYLOAD);

        $restored = new GaugeState();
        $restored->restoreFromPersistence($saved->exportForPersistence());

        self::assertSame($saved->snapshot(), $restored->snapshot());
    }

    public function testRestoringACorruptPayloadLeavesTheStateEmpty(): void
    {
        $gauges = new GaugeState();

        $gauges->restoreFromPersistence(['gauges' => 'not a map']);

        self::assertSame([], $gauges->list());
    }

    public function testRestoringWithoutAnyGaugeKeyLeavesTheStateEmpty(): void
    {
        $gauges = new GaugeState();

        $gauges->restoreFromPersistence([]);

        self::assertSame([], $gauges->list());
    }

    public function testDomainKeyNamesTheGaugeSection(): void
    {
        self::assertSame('gauges', new GaugeState()->domainKey());
    }
}
