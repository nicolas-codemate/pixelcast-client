<?php

declare(strict_types=1);

namespace App\Tests\Client\Gauge;

use App\Client\Color;
use App\Client\Gauge\GaugePayload;
use App\Client\Gauge\GaugeRow;
use App\Client\StaleBehavior;
use App\Client\Text\PolymorphicTextField;
use App\Client\Text\TextSegment;
use PHPUnit\Framework\TestCase;

final class GaugePayloadTest extends TestCase
{
    public function testAnEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty name/');

        GaugePayload::create(name: '', rows: [self::buildRow()]);
    }

    public function testANameOneCharacterOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name/');

        GaugePayload::create(name: str_repeat('a', GaugePayload::MAXIMUM_NAME_LENGTH + 1), rows: [self::buildRow()]);
    }

    public function testANameExactlyAtTheLimitIsAccepted(): void
    {
        $nameAtTheLimit = str_repeat('a', GaugePayload::MAXIMUM_NAME_LENGTH);

        $gauge = GaugePayload::create(name: $nameAtTheLimit, rows: [self::buildRow()]);

        self::assertSame($nameAtTheLimit, $gauge->name);
    }

    public function testATitleOneCharacterOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title/');

        GaugePayload::create(
            name: 'claude',
            rows: [self::buildRow()],
            title: str_repeat('a', GaugePayload::MAXIMUM_TITLE_LENGTH + 1),
        );
    }

    public function testOneRowOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 9 rows, got 10/');

        GaugePayload::create(name: 'claude', rows: self::buildRows(GaugePayload::MAXIMUM_ROWS + 1));
    }

    public function testAsManyRowsAsTheLimitAllowsIsAccepted(): void
    {
        $gauge = GaugePayload::create(name: 'claude', rows: self::buildRows(GaugePayload::MAXIMUM_ROWS));

        self::assertCount(GaugePayload::MAXIMUM_ROWS, $gauge->toArray()['rows']);
    }

    public function testSerializationLeavesTheNameOutOfTheBody(): void
    {
        $gauge = GaugePayload::create(name: 'claude', rows: [self::buildRow()]);

        self::assertArrayNotHasKey('name', $gauge->toArray());
    }

    public function testSerializationOmitsEveryUnsetHeaderField(): void
    {
        $gauge = GaugePayload::create(name: 'claude', rows: [self::buildRow()]);

        self::assertSame(['rows' => [['label' => '5h', 'percent' => 41]]], $gauge->toArray());
    }

    public function testSerializationCarriesEveryHeaderFieldThatWasSet(): void
    {
        $gauge = GaugePayload::create(
            name: 'claude',
            rows: [self::buildRow()],
            title: 'Claude',
            iconName: 'claude',
            displayDurationMilliseconds: 10000,
            staleAfterInSeconds: 2700,
            staleBehavior: StaleBehavior::Dim,
        );

        self::assertSame([
            'title' => 'Claude',
            'icon' => 'claude',
            'rows' => [['label' => '5h', 'percent' => 41]],
            'duration' => 10000,
            'staleAfter' => 2700,
            'staleBehavior' => 'dim',
        ], $gauge->toArray());
    }

    public function testASegmentedTitleIsSerializedAsColoredPairs(): void
    {
        $gauge = GaugePayload::create(
            name: 'claude',
            rows: [self::buildRow()],
            title: PolymorphicTextField::fromSegments(
                TextSegment::create('Claude', Color::fromHexCode('#D97757')),
                TextSegment::create(' Max', Color::fromHexCode('#ffffff')),
            ),
        );

        self::assertSame(
            [['t' => 'Claude', 'c' => '#D97757'], ['t' => ' Max', 'c' => '#FFFFFF']],
            $gauge->toArray()['title'] ?? null,
        );
    }

    public function testASegmentedTitleOneSegmentOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title holds at most '.GaugePayload::MAXIMUM_TITLE_SEGMENTS.' colored segments/');

        GaugePayload::create(
            name: 'claude',
            rows: [self::buildRow()],
            title: self::buildSegmentedField(GaugePayload::MAXIMUM_TITLE_SEGMENTS + 1, 1),
        );
    }

    public function testSegmentsOfATitleShareTheSameCharacterBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title holds at most '.GaugePayload::MAXIMUM_TITLE_LENGTH.' characters, got 32/');

        GaugePayload::create(
            name: 'claude',
            rows: [self::buildRow()],
            title: self::buildSegmentedField(2, 16),
        );
    }

    private static function buildSegmentedField(int $segmentCount, int $charactersPerSegment): PolymorphicTextField
    {
        $segment = TextSegment::create(str_repeat('a', $charactersPerSegment), Color::fromHexCode('#FFFFFF'));

        return PolymorphicTextField::fromSegments(...array_fill(0, $segmentCount, $segment));
    }

    private static function buildRow(): GaugeRow
    {
        return GaugeRow::create(label: '5h', percent: 41);
    }

    /**
     * @return list<GaugeRow>
     */
    private static function buildRows(int $rowCount): array
    {
        return array_map(
            static fn (int $index): GaugeRow => GaugeRow::create(label: 'row'.$index, percent: $index),
            range(1, $rowCount),
        );
    }
}
