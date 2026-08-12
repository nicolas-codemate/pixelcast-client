<?php

declare(strict_types=1);

namespace App\Tests\Client\Text;

use App\Client\Color;
use App\Client\Text\PolymorphicTextField;
use App\Client\Text\TextSegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolymorphicTextFieldTest extends TestCase
{
    private const string FIELD_DESCRIPTION = 'gauge row label';
    private const int MAXIMUM_CHARACTERS = 11;
    private const int MAXIMUM_SEGMENTS = 3;

    public function testAPlainTextFieldIsSerializedAsABareString(): void
    {
        $field = PolymorphicTextField::fromPlainText('5h');

        self::assertSame('5h', $field->toPayloadValue());
        self::assertSame('5h', $field->toPlainText());
    }

    public function testAColoredTextFieldIsSerializedAsASingleSegment(): void
    {
        $field = PolymorphicTextField::fromColoredText('Claude', Color::fromHexCode('#D97757'));

        self::assertSame([['t' => 'Claude', 'c' => '#D97757']], $field->toPayloadValue());
        self::assertSame('Claude', $field->toPlainText());
    }

    public function testASegmentedFieldKeepsTheSegmentsInOrder(): void
    {
        $field = PolymorphicTextField::fromSegments(
            TextSegment::create('fable', Color::fromHexCode('#FFFFFF')),
            TextSegment::create(' reset', Color::fromHexCode('#888888')),
        );

        self::assertSame(
            [
                ['t' => 'fable', 'c' => '#FFFFFF'],
                ['t' => ' reset', 'c' => '#888888'],
            ],
            $field->toPayloadValue(),
        );
        self::assertSame('fable reset', $field->toPlainText());
    }

    public function testAFieldWithoutAnySegmentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A colored text field needs at least one segment.');

        PolymorphicTextField::fromSegments();
    }

    /**
     * @return iterable<string, array{PolymorphicTextField}>
     */
    public static function provideFieldsExactlyAtTheCharacterLimit(): iterable
    {
        yield 'plain text' => [PolymorphicTextField::fromPlainText('fable reset')];
        yield 'single colored segment' => [PolymorphicTextField::fromColoredText('fable reset', self::whiteColor())];
        yield 'two segments sharing the budget' => [PolymorphicTextField::fromSegments(
            TextSegment::create('fable', self::whiteColor()),
            TextSegment::create(' reset', self::whiteColor()),
        )];
    }

    #[DataProvider('provideFieldsExactlyAtTheCharacterLimit')]
    public function testAFieldExactlyAtTheCharacterLimitIsAccepted(PolymorphicTextField $field): void
    {
        $field->assertFitsWithin(self::FIELD_DESCRIPTION, self::MAXIMUM_CHARACTERS, self::MAXIMUM_SEGMENTS);

        self::assertSame('fable reset', $field->toPlainText());
    }

    /**
     * @return iterable<string, array{PolymorphicTextField}>
     */
    public static function provideFieldsOneCharacterOverTheLimit(): iterable
    {
        yield 'plain text' => [PolymorphicTextField::fromPlainText('fables reset')];
        yield 'single colored segment' => [PolymorphicTextField::fromColoredText('fables reset', self::whiteColor())];
        yield 'two segments sharing the budget' => [PolymorphicTextField::fromSegments(
            TextSegment::create('fables', self::whiteColor()),
            TextSegment::create(' reset', self::whiteColor()),
        )];
    }

    #[DataProvider('provideFieldsOneCharacterOverTheLimit')]
    public function testAFieldOneCharacterOverTheLimitIsRejected(PolymorphicTextField $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A gauge row label holds at most 11 characters, got 12.');

        $field->assertFitsWithin(self::FIELD_DESCRIPTION, self::MAXIMUM_CHARACTERS, self::MAXIMUM_SEGMENTS);
    }

    public function testTheCharacterBudgetIsSharedByEverySegment(): void
    {
        $field = PolymorphicTextField::fromSegments(
            TextSegment::create('123456', self::whiteColor()),
            TextSegment::create('654321', self::whiteColor()),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A gauge row label holds at most 11 characters, got 12.');

        $field->assertFitsWithin(self::FIELD_DESCRIPTION, self::MAXIMUM_CHARACTERS, self::MAXIMUM_SEGMENTS);
    }

    public function testAFieldExactlyAtTheSegmentLimitIsAccepted(): void
    {
        $field = PolymorphicTextField::fromSegments(
            TextSegment::create('a', self::whiteColor()),
            TextSegment::create('b', self::whiteColor()),
            TextSegment::create('c', self::whiteColor()),
        );

        $field->assertFitsWithin(self::FIELD_DESCRIPTION, self::MAXIMUM_CHARACTERS, self::MAXIMUM_SEGMENTS);

        self::assertCount(3, (array) $field->toPayloadValue());
    }

    public function testAFieldOneSegmentOverTheLimitIsRejected(): void
    {
        $field = PolymorphicTextField::fromSegments(
            TextSegment::create('a', self::whiteColor()),
            TextSegment::create('b', self::whiteColor()),
            TextSegment::create('c', self::whiteColor()),
            TextSegment::create('d', self::whiteColor()),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A gauge row label holds at most 3 colored segments, got 4.');

        $field->assertFitsWithin(self::FIELD_DESCRIPTION, self::MAXIMUM_CHARACTERS, self::MAXIMUM_SEGMENTS);
    }

    public function testTheFieldDescriptionNamesTheFaultyField(): void
    {
        $field = PolymorphicTextField::fromPlainText(str_repeat('a', 32));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A gauge title holds at most 31 characters, got 32.');

        $field->assertFitsWithin('gauge title', 31, 4);
    }

    private static function whiteColor(): Color
    {
        return Color::fromHexCode('#FFFFFF');
    }
}
