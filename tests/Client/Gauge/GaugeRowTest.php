<?php

declare(strict_types=1);

namespace App\Tests\Client\Gauge;

use App\Client\Color;
use App\Client\Gauge\GaugeRow;
use App\Client\Text\PolymorphicTextField;
use App\Client\Text\TextSegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GaugeRowTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function boundedTextFieldProvider(): iterable
    {
        yield 'label' => ['label', GaugeRow::MAXIMUM_LABEL_LENGTH];
        yield 'info' => ['info', GaugeRow::MAXIMUM_INFO_LENGTH];
        yield 'value' => ['value', GaugeRow::MAXIMUM_VALUE_LENGTH];
        yield 'note' => ['note', GaugeRow::MAXIMUM_NOTE_LENGTH];
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function clampedPercentProvider(): iterable
    {
        yield 'below the floor' => [-5, GaugeRow::MINIMUM_PERCENT];
        yield 'above the ceiling' => [140, GaugeRow::MAXIMUM_PERCENT];
        yield 'on the floor' => [0, 0];
        yield 'on the ceiling' => [100, 100];
        yield 'inside the range' => [41, 41];
    }

    #[DataProvider('boundedTextFieldProvider')]
    public function testATextFieldExactlyAtItsLimitIsAccepted(string $fieldName, int $maximumLength): void
    {
        $valueAtTheLimit = str_repeat('a', $maximumLength);

        $row = self::buildRowWithTextField($fieldName, $valueAtTheLimit);

        self::assertSame($valueAtTheLimit, $row->toArray()[$fieldName] ?? null);
    }

    #[DataProvider('boundedTextFieldProvider')]
    public function testATextFieldOneCharacterOverItsLimitIsRejected(string $fieldName, int $maximumLength): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.$fieldName.'/');

        self::buildRowWithTextField($fieldName, str_repeat('a', $maximumLength + 1));
    }

    #[DataProvider('clampedPercentProvider')]
    public function testPercentIsClampedIntoTheFirmwareRange(int $requestedPercent, int $expectedPercent): void
    {
        $row = GaugeRow::create(label: '5h', percent: $requestedPercent);

        self::assertSame($expectedPercent, $row->percent);
    }

    public function testSerializationOmitsEveryUnsetField(): void
    {
        $row = GaugeRow::create(label: '5h', percent: 41);

        self::assertSame(['label' => '5h', 'percent' => 41], $row->toArray());
    }

    public function testSerializationEmitsAZeroPercent(): void
    {
        $row = GaugeRow::create(label: 'fable', percent: 0);

        self::assertSame(['label' => 'fable', 'percent' => 0], $row->toArray());
    }

    public function testSerializationCarriesEveryFieldWithColorsAsHexCodes(): void
    {
        $row = GaugeRow::create(
            label: '5h',
            percent: 41,
            info: '14:50',
            value: '41%',
            note: 'x1.2^',
            barColor: Color::fromHexCode('#4caf50'),
            noteColor: Color::fromHexCode('#FFC107'),
        );

        self::assertSame([
            'label' => '5h',
            'info' => '14:50',
            'value' => '41%',
            'percent' => 41,
            'note' => 'x1.2^',
            'color' => '#4CAF50',
            'noteColor' => '#FFC107',
        ], $row->toArray());
    }

    public function testASegmentedLabelIsSerializedAsColoredPairs(): void
    {
        $row = GaugeRow::create(
            label: PolymorphicTextField::fromSegments(
                TextSegment::create('5h', Color::fromHexCode('#FFFFFF')),
                TextSegment::create(' reset', Color::fromHexCode('#888888')),
            ),
            percent: 41,
        );

        self::assertSame([
            'label' => [['t' => '5h', 'c' => '#FFFFFF'], ['t' => ' reset', 'c' => '#888888']],
            'percent' => 41,
        ], $row->toArray());
    }

    public function testASegmentedLabelExactlyAtTheCharacterLimitIsAcceptedWhole(): void
    {
        $row = GaugeRow::create(
            label: PolymorphicTextField::fromSegments(
                TextSegment::create('fable', Color::fromHexCode('#FFFFFF')),
                TextSegment::create(' reset', Color::fromHexCode('#888888')),
            ),
            percent: 3,
        );

        self::assertSame(
            [['t' => 'fable', 'c' => '#FFFFFF'], ['t' => ' reset', 'c' => '#888888']],
            $row->toArray()['label'],
        );
    }

    public function testASegmentedLabelOneSegmentOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/label holds at most '.GaugeRow::MAXIMUM_LABEL_SEGMENTS.' colored segments/');

        GaugeRow::create(label: self::buildSegmentedField(GaugeRow::MAXIMUM_LABEL_SEGMENTS + 1, 1), percent: 41);
    }

    public function testSegmentsOfALabelShareTheSameCharacterBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/label holds at most '.GaugeRow::MAXIMUM_LABEL_LENGTH.' characters, got 12/');

        GaugeRow::create(label: self::buildSegmentedField(2, 6), percent: 41);
    }

    private static function buildSegmentedField(int $segmentCount, int $charactersPerSegment): PolymorphicTextField
    {
        $segment = TextSegment::create(str_repeat('a', $charactersPerSegment), Color::fromHexCode('#FFFFFF'));

        return PolymorphicTextField::fromSegments(...array_fill(0, $segmentCount, $segment));
    }

    private static function buildRowWithTextField(string $fieldName, string $value): GaugeRow
    {
        return GaugeRow::create(
            label: 'label' === $fieldName ? $value : '5h',
            percent: 41,
            info: 'info' === $fieldName ? $value : null,
            value: 'value' === $fieldName ? $value : null,
            note: 'note' === $fieldName ? $value : null,
        );
    }
}
